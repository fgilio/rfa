<?php

use App\Actions\AddCommentAction;
use App\Actions\BackfillGlobalGitignoreAction;
use App\Concerns\InteractsWithRemoteLinks;
use App\Actions\CleanExpiredTrashAction;
use App\Actions\DeleteCommentAction;
use App\Actions\DeleteReviewFilesAction;
use App\Actions\DeleteTrashedFileAction;
use App\Actions\DiscardFileChangesAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetCurrentHeadAction;
use App\Actions\GetFileListAction;
use App\Actions\GroupReviewFilesAction;
use App\Actions\ScanReviewFilesAction;
use App\Actions\LoadCommitMetadataAction;
use App\Actions\ResolveCommitAction;
use App\Actions\ResolveProjectAction;
use App\Actions\ResolveRangeAction;
use App\Actions\ResolveRangeToWorkingAction;
use App\Actions\ResolveStartupRouteAction;
use App\Actions\RestoreDiscardedFileAction;
use App\Actions\SessionStateAction;
use App\Actions\ToggleReviewedAction;
use App\Actions\UpdateCommentAction;
use App\Actions\UpdateProjectSettingAction;
use App\DTOs\CurrentHeadResult;
use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\Enums\DivergenceState;
use App\Exceptions\GitCommandException;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithRemoteLinks;

    /** @var array<int, array<string, mixed>> */
    public array $files = [];

    /** @var array<int, array<string, mixed>> */
    public array $reviewPairs = [];

    /** @var array<int, array<string, mixed>> */
    public array $sourceFiles = [];

    /** @var array<int, array<string, mixed>> */
    public array $comments = [];

    public string $globalComment = '';

    public string $repoPath = '';

    public int $projectId = 0;

    public string $projectName = '';

    public string $projectBranch = '';

    public string $projectSlug = '';

    public bool $hasRemote = false;

    public ?string $exportResult = null;

    public bool $submitted = false;

    public ?string $gitError = null;

    /** @var array<string, string> */
    public array $reviewedFiles = [];

    public ?string $activeFileId = null;

    /** @var array<int, array<string, mixed>> */
    public array $trashedFiles = [];

    public bool $respectGlobalGitignore = true;

    public ?string $globalGitignorePath = null;

    #[Locked]
    public string $diffFrom = 'HEAD';

    #[Locked]
    public ?string $diffTo = null;

    /** @var array{shortHash: string, message: string, author: string, prevHash: ?string, nextHash: ?string}|null */
    #[Locked]
    public ?array $commitInfo = null;

    public DivergenceState $divergenceState = DivergenceState::Aligned;

    /** @var array<string, mixed> */
    public array $divergenceContext = [];

    /** HEAD sha at which the user last dismissed a divergence banner. */
    public ?string $dismissedAtHead = null;

    /** Guards `skipRender()` on poll ticks so the initial mount still renders. */
    #[Locked]
    public bool $divergenceChecked = false;

    /*
    |----------------------------------------------------------------------
    | Responsibility clusters (5):
    |   1. Initialization & Diff Context  (mount..refreshFileList)
    |   2. Comment Management             (addComment..restoreComments)
    |   3. Trash & Discard                (discardFileChanges..undo)
    |   4. Review State & Export           (toggleReviewed..submitReview)
    |   5. Computed, Helpers & Persistence (groupedComments..loadTrashedFiles)
    |
    | Shared deps: $files, $comments, $reviewedFiles, saveSession()
    | Extraction blocker: 1+N hydration (see resources/CLAUDE.md)
    |----------------------------------------------------------------------
    */

    // region: Initialization & Diff Context

    public function mount(string $slug, ?string $hash = null, ?string $ref = null, ?string $baseRef = null, ?string $from = null, ?string $to = null, ?string $rangeFromWorking = null): void
    {
        $project = app(ResolveProjectAction::class)->handle($slug, touch: true) ?? abort(404);
        $this->repoPath = $project['path'];
        $this->projectId = $project['id'];
        $this->projectName = $project['name'];
        $this->projectBranch = $project['branch'] ?? '';
        $this->projectSlug = $project['slug'];
        $this->hasRemote = ! empty($project['remote_url']);

        app(ResolveStartupRouteAction::class)->rememberLastOpened($slug);

        if (config('nativephp-internal.running')) {
            \Native\Desktop\Facades\Window::get('main')->title("rfa - {$this->projectName}");
        }

        $this->respectGlobalGitignore = $project['respect_global_gitignore'] ?? true;
        $this->globalGitignorePath = $project['global_gitignore_path'] ?: null;

        // Commit mode: resolve hash to full SHA
        if ($hash !== null) {
            $target = app(ResolveCommitAction::class)->handle($this->repoPath, $hash);

            if ($target === null) {
                abort(404, 'Invalid commit reference');
            }

            $this->diffFrom = $target->from();
            $this->diffTo = $target->to();
            $this->loadCommitInfo();
        } elseif ($from !== null && $to !== null) {
            // Explicit range mode: /p/{slug}/r/{from}..{to}
            $target = app(ResolveRangeAction::class)->handle($this->repoPath, $from, $to);
            $this->diffFrom = $target->from();
            $this->diffTo = $target->to();
            $this->loadCommitInfo();
        } elseif ($rangeFromWorking !== null) {
            // Range-to-working mode: /p/{slug}/rw/{from} — commits from $from through the working tree.
            $target = app(ResolveRangeToWorkingAction::class)->handle($this->repoPath, $rangeFromWorking);
            $this->diffFrom = $target->from();
            $this->diffTo = $target->to();
        } elseif ($ref !== null) {
            // Range mode from URL params
            $target = app(ResolveRangeAction::class)->handle($this->repoPath, $baseRef, $ref);
            $this->diffFrom = $target->from();
            $this->diffTo = $target->to();
        }

        // Backfill path for projects registered before the migration
        if ($this->globalGitignorePath === null) {
            $this->globalGitignorePath = app(BackfillGlobalGitignoreAction::class)
                ->handle($this->projectId, $this->repoPath);
        }

        $this->rehydrateForTarget();
        $this->checkHeadDivergence();
    }

    private function rehydrateForTarget(): void
    {
        $this->refreshFileList();
        $this->scanReviewFiles();

        $target = $this->buildDiffTarget();
        $session = app(SessionStateAction::class)->handle($this->repoPath, $this->files, $this->projectId, $target);
        $this->comments = $session['comments'];
        $this->reviewedFiles = $session['reviewedFiles'];
        $this->globalComment = $session['globalComment'];

        if (! empty($session['orphanedPaths'])) {
            $this->injectOrphanedFiles($session['orphanedPaths']);
        }

        $this->loadTrashedFiles();
    }

    public function isCommitMode(): bool
    {
        return $this->diffTo !== null;
    }

    private ?DiffTarget $cachedTarget = null;

    private function buildDiffTarget(): DiffTarget
    {
        return $this->cachedTarget ??= $this->diffTo !== null
            ? DiffTarget::range($this->diffFrom, $this->diffTo)
            : DiffTarget::rangeToWorking($this->diffFrom);
    }

    private function loadCommitInfo(): void
    {
        if ($this->diffTo === null) {
            return;
        }

        $this->commitInfo = app(LoadCommitMetadataAction::class)
            ->handle($this->repoPath, $this->diffTo, $this->diffFrom);
    }

    private function refreshFileList(bool $clearCache = true): void
    {
        $target = $this->buildDiffTarget();

        try {
            $this->files = app(GetFileListAction::class)->handle(
                $this->repoPath,
                clearCache: $clearCache,
                projectId: $this->projectId,
                globalGitignorePath: $this->diffTo !== null ? null : ($this->respectGlobalGitignore ? $this->globalGitignorePath : null),
                target: $target,
            );
        } catch (GitCommandException $e) {
            $this->gitError = $e->stderr ?: $e->getMessage();
            $this->files = [];
        }

        $this->groupFiles();
    }

    public function updatedRespectGlobalGitignore(): void
    {
        app(UpdateProjectSettingAction::class)->handle($this->projectId, [
            'respect_global_gitignore' => $this->respectGlobalGitignore,
        ]);

        $this->refreshFileList();

        $target = $this->buildDiffTarget();
        $session = app(SessionStateAction::class)->handle($this->repoPath, $this->files, $this->projectId, $target);
        $this->comments = $session['comments'];
        $this->reviewedFiles = $session['reviewedFiles'];

        if (! empty($session['orphanedPaths'])) {
            $this->injectOrphanedFiles($session['orphanedPaths']);
        }
    }

    // endregion: Initialization & Diff Context

    // region: Branch Divergence

    #[On('head-divergence-transitioned')]
    public function checkHeadDivergence(): void
    {
        if ($this->isCommitMode()) {
            if ($this->divergenceChecked) {
                $this->skipRender();
            }
            $this->divergenceChecked = true;

            return;
        }

        $before = [$this->divergenceState, $this->divergenceContext, $this->dismissedAtHead, $this->projectBranch];

        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->projectBranch ?: null);
        $this->resolveDivergenceState($head);

        $after = [$this->divergenceState, $this->divergenceContext, $this->dismissedAtHead, $this->projectBranch];

        if ($this->divergenceChecked && $before === $after) {
            $this->skipRender();
        }

        $this->divergenceChecked = true;
    }

    private function resolveDivergenceState(CurrentHeadResult $head): void
    {
        // Sentinel: GetCurrentHeadAction returns sha='' when git fails transiently
        // (e.g. mid-rebase). Leave state untouched and retry next tick.
        if ($head->sha === '') {
            return;
        }

        if (! $head->detached && $head->branch === $this->projectBranch) {
            $this->markAligned();

            return;
        }

        $target = $this->projectBranch;

        if ($head->detached) {
            if ($this->dismissedAtHead === $head->sha) {
                $this->markAligned();

                return;
            }

            $this->divergenceState = DivergenceState::Detached;
            $this->divergenceContext = [
                'target' => $target,
                'currentBranch' => null,
                'currentSha' => $head->sha,
                'shortSha' => substr($head->sha, 0, 7),
            ];

            return;
        }

        if ($target !== '' && $head->targetExists === false) {
            $this->divergenceState = DivergenceState::MissingTarget;
            $this->divergenceContext = [
                'target' => $target,
                'currentBranch' => $head->branch,
                'currentSha' => $head->sha,
                'shortSha' => substr($head->sha, 0, 7),
            ];

            return;
        }

        if (! $this->hasPersistedComments()) {
            $this->autoFollowToHead((string) $head->branch);

            return;
        }

        if ($this->dismissedAtHead === $head->sha) {
            $this->markAligned();

            return;
        }

        $this->divergenceState = DivergenceState::Diverged;
        $this->divergenceContext = [
            'target' => $target,
            'currentBranch' => $head->branch,
            'currentSha' => $head->sha,
            'shortSha' => substr($head->sha, 0, 7),
        ];
    }

    public function switchReviewToHead(): void
    {
        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->projectBranch ?: null);

        if ($head->detached || $head->branch === null || $head->branch === '') {
            return;
        }

        $this->autoFollowToHead($head->branch);
    }

    public function keepReviewing(): void
    {
        $currentSha = $this->divergenceContext['currentSha'] ?? null;

        if (is_string($currentSha) && $currentSha !== '') {
            $this->dismissedAtHead = $currentSha;
        }

        $this->markAligned();
    }

    public function dismissDetachedBanner(): void
    {
        $this->keepReviewing();
    }

    private function autoFollowToHead(string $newBranch): void
    {
        // Race guard: overlapping polls during a slow rehydrate can re-enter here.
        if ($this->projectBranch === $newBranch) {
            $this->markAligned();

            return;
        }

        app(UpdateProjectSettingAction::class)->handle($this->projectId, ['branch' => $newBranch]);

        $this->projectBranch = $newBranch;
        $this->cachedTarget = null;
        $this->dismissedAtHead = null;
        $this->markAligned();

        $this->rehydrateForTarget();
    }

    private function markAligned(): void
    {
        $this->divergenceState = DivergenceState::Aligned;
        $this->divergenceContext = [];
    }

    private function hasPersistedComments(): bool
    {
        $projectId = $this->projectId === 0 ? null : $this->projectId;

        return \App\Models\Comment::forProjectOrRepo($projectId, $this->repoPath)->exists();
    }

    // endregion: Branch Divergence

    // region: Comment Management

    #[On('add-comment')]
    public function addComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body, ?string $lineSnippet = null): void
    {
        $this->createComment($fileId, $side, $startLine, $endLine, $body, $lineSnippet, isDraft: false);
    }

    #[On('add-draft-comment')]
    public function addDraftComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body, ?string $lineSnippet = null): void
    {
        $this->createComment($fileId, $side, $startLine, $endLine, $body, $lineSnippet, isDraft: true);
    }

    private function createComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body, ?string $lineSnippet, bool $isDraft): void
    {
        $comment = app(AddCommentAction::class)->handle(
            $this->repoPath,
            $this->projectId ?: null,
            $this->buildDiffTarget(),
            $this->files,
            $fileId,
            $side,
            $startLine,
            $endLine,
            $body,
            $isDraft,
            $lineSnippet,
        );

        if (! $comment) {
            return;
        }

        $this->comments[] = $comment;
        $this->dispatchFileComments($fileId);
        $this->checkHeadDivergence();
    }

    #[On('update-comment')]
    public function updateComment(string $commentId, string $body, bool $isDraft = false): void
    {
        $index = collect($this->comments)->search(fn ($c) => $c['id'] === $commentId);

        if ($index === false) {
            return;
        }

        if (! app(UpdateCommentAction::class)->handle($commentId, $body, $isDraft)) {
            return;
        }

        $this->comments[$index]['body'] = $body;
        $this->comments[$index]['isDraft'] = $isDraft;
        $fileId = $this->comments[$index]['fileId'];

        $this->dispatchFileComments($fileId);
        $this->skipRender();
    }

    #[On('delete-comment')]
    public function deleteComment(string $commentId): void
    {
        $deletedComment = collect($this->comments)->firstWhere('id', $commentId);
        $fileId = $deletedComment['fileId'] ?? null;

        $result = app(DeleteCommentAction::class)->handle($this->comments, $commentId);

        if ($result === null) {
            return;
        }

        $this->comments = $result;

        if ($fileId) {
            $this->dispatchFileComments($fileId);
        }

        if ($deletedComment) {
            $this->dispatch('undo-available', type: 'delete', payload: [$deletedComment], message: 'Comment deleted');
        }

        $this->checkHeadDivergence();
    }

    public function clearAllComments(): void
    {
        if (empty($this->comments)) {
            return;
        }

        $deletedComments = $this->comments;

        \App\Models\Comment::whereIn('id', array_column($deletedComments, 'id'))->delete();

        $this->comments = [];

        collect($deletedComments)
            ->pluck('fileId')
            ->unique()
            ->each(fn (string $fileId) => $this->dispatchFileComments($fileId));

        $count = count($deletedComments);
        $this->dispatch('undo-available', type: 'clear-all', payload: $deletedComments,
            message: "Cleared {$count} comment".($count === 1 ? '' : 's'));
        $this->checkHeadDivergence();
    }

    /** @param  array<int, array<string, mixed>>  $comments */
    public function restoreComments(array $comments): void
    {
        $merged = $this->mergeComments($comments);

        if (empty($merged)) {
            return;
        }

        foreach ($merged as $c) {
            \App\Models\Comment::updateOrCreate(
                ['id' => $c['id']],
                [
                    'project_id' => $this->projectId ?: null,
                    'repo_path' => $this->repoPath,
                    'origin_ref' => $c['originRef'] ?? 'working',
                    'file_path' => $c['file'] ?? '',
                    'side' => $c['side'] ?? 'right',
                    'start_line' => $c['startLine'] ?? null,
                    'end_line' => $c['endLine'] ?? null,
                    'file_content_hash' => $c['fileContentHash'] ?? null,
                    'line_snippet' => $c['lineSnippet'] ?? null,
                    'body' => $c['body'] ?? '',
                    'is_draft' => (bool) ($c['isDraft'] ?? false),
                    'submitted_at' => null,
                ],
            );
        }

        collect($merged)
            ->pluck('fileId')
            ->unique()
            ->each(fn (string $fileId) => $this->dispatchFileComments($fileId));

        $this->checkHeadDivergence();
    }

    // endregion: Comment Management

    // region: Trash & Discard

    #[On('discard-file')]
    public function discardFileChanges(string $fileId): void
    {
        if ($this->isCommitMode()) {
            return;
        }

        $file = collect($this->files)->firstWhere('id', $fileId);
        if (! $file || $file['status'] === 'commented') {
            return;
        }

        $fileComments = collect($this->comments)->where('fileId', $fileId)->values()->all();

        try {
            $trashRecord = app(DiscardFileChangesAction::class)->handle(
                repoPath: $this->repoPath,
                path: $file['path'],
                status: $file['status'],
                projectId: $this->projectId,
                oldPath: $file['oldPath'] ?? null,
                isUntracked: $file['isUntracked'] ?? false,
                isSymlink: $file['isSymlink'] ?? false,
                comments: $fileComments,
            );
        } catch (\Throwable $e) {
            $message = $e instanceof GitCommandException ? $e->stderr : $e->getMessage();
            Flux::toast(variant: 'danger', text: 'Discard failed: '.$message);
            $this->skipRender();

            return;
        }

        // Remove comments for discarded file
        $this->comments = array_values(
            array_filter($this->comments, fn ($c) => $c['fileId'] !== $fileId)
        );

        // Invalidate diff cache for this file
        $projectKey = $this->projectId > 0 ? $this->projectId : $this->repoPath;
        Cache::forget(DiffCacheKey::for($projectKey, $fileId, $this->buildDiffTarget()->contextKey()));

        unset($this->reviewedFiles[$file['path']]);

        $this->refreshFileList();
        $this->saveSession();
        $this->loadTrashedFiles();

        $commentCount = count($fileComments);
        $message = $commentCount > 0
            ? 'Discarded '.basename($file['path']).' — '.$commentCount.' comment'.($commentCount === 1 ? '' : 's').' removed'
            : 'Discarded '.basename($file['path']);
        $this->dispatch('undo-available', type: 'discard', payload: $trashRecord->id, message: $message);
        $this->dispatch('fingerprint-reset');
    }

    public function restoreDiscardedFile(int $trashId): void
    {
        try {
            $comments = app(RestoreDiscardedFileAction::class)->handle($trashId, $this->repoPath, $this->projectId);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: 'Restore failed');
            $this->skipRender();

            return;
        }

        $this->mergeComments($comments);
        $this->refreshFileList();
        $this->saveSession();
        $this->loadTrashedFiles();

        Flux::toast(text: 'Changes restored');
    }

    public function permanentlyDeleteTrashed(int $trashId): void
    {
        app(DeleteTrashedFileAction::class)->handle($trashId, $this->projectId);
        $this->loadTrashedFiles();
    }

    public function undo(string $type, mixed $payload): void
    {
        match ($type) {
            'delete', 'clear-all' => $this->restoreComments($payload),
            'discard' => $this->restoreDiscardedFile($payload),
            default => null,
        };
    }

    // endregion: Trash & Discard

    // region: Review State & Export

    #[On('toggle-reviewed')]
    public function toggleReviewed(string $filePath): void
    {
        $result = app(ToggleReviewedAction::class)->handle(
            $this->reviewedFiles,
            $filePath,
            $this->files,
            $this->repoPath,
            $this->buildDiffTarget(),
            $this->projectId ?: null,
        );

        if ($result === null) {
            return;
        }

        $this->reviewedFiles = $result;
        $this->skipRender();
    }

    public function updatedGlobalComment(): void
    {
        $this->saveSession();
        $this->skipRender();
    }

    public function submitReview(): void
    {
        $this->saveSession();

        $target = $this->buildDiffTarget();
        $finalizedComments = array_values(array_filter($this->comments, fn ($c) => ! ($c['isDraft'] ?? false)));

        $result = app(ExportReviewAction::class)->handle($this->repoPath, $finalizedComments, $this->globalComment, $this->files, $target);

        $this->exportResult = $result['clipboard'];
        $this->submitted = true;

        $this->scanReviewFiles();

        Flux::toast(variant: 'success', heading: 'Review submitted', text: $this->exportResult);
        $this->dispatch('copy-to-clipboard', text: $result['clipboard']);

        // Only drop comments the export actually submitted; drafts and out-of-scope
        // comments (e.g. hash-anchored from another selection) stay in the pool.
        $submittedIds = $result['submittedIds'];
        $affectedFileIds = collect($this->comments)
            ->whereIn('id', $submittedIds)
            ->pluck('fileId')
            ->unique();
        $this->comments = array_values(array_filter(
            $this->comments,
            fn ($c) => ! in_array($c['id'], $submittedIds, true),
        ));
        $this->globalComment = '';
        $this->saveSession();

        $affectedFileIds->each(fn (string $fileId) => $this->dispatchFileComments($fileId));
    }

    // endregion: Review State & Export

    // region: Computed, Helpers & Persistence

    /** @return array<string, array<int, array<string, mixed>>> */
    #[Computed]
    public function groupedComments(): array
    {
        return collect($this->comments)->groupBy('fileId')->map(fn ($group) => $group->values()->all())->all();
    }

    public function deleteReviewPair(string $basename): void
    {
        app(DeleteReviewFilesAction::class)->handle($this->repoPath, $basename);

        $this->reviewPairs = array_values(
            array_filter($this->reviewPairs, fn ($p) => $p['basename'] !== $basename)
        );

        Flux::toast(text: 'Review deleted', variant: 'success');
    }

    public function deleteAllReviewPairs(): void
    {
        $basenames = array_column($this->reviewPairs, 'basename');

        if (empty($basenames)) {
            return;
        }

        app(DeleteReviewFilesAction::class)->handle($this->repoPath, $basenames);

        $this->reviewPairs = [];

        Flux::toast(text: 'All reviews deleted', variant: 'success');
    }

    /** @param array<int, string> $orphanedPaths */
    private function injectOrphanedFiles(array $orphanedPaths): void
    {
        foreach ($orphanedPaths as $path) {
            $entry = new FileListEntry(
                path: $path,
                status: 'commented',
                oldPath: null,
                additions: 0,
                deletions: 0,
                isBinary: false,
                isUntracked: false,
            );
            $this->files[] = $entry->toArray();
        }
        $this->groupFiles();
    }

    private function groupFiles(): void
    {
        $grouped = app(GroupReviewFilesAction::class)->handle($this->files);
        $this->sourceFiles = $grouped['sourceFiles'];
    }

    private function scanReviewFiles(): void
    {
        $this->reviewPairs = app(ScanReviewFilesAction::class)->handle($this->repoPath);
    }

    private function dispatchFileComments(string $fileId): void
    {
        $fileComments = collect($this->comments)->where('fileId', $fileId)->values()->all();
        $this->dispatch('comment-updated', fileId: $fileId, comments: $fileComments);
    }

    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<int, array<string, mixed>> newly merged comments (empty if none added)
     */
    private function mergeComments(array $comments): array
    {
        if (empty($comments)) {
            return [];
        }

        $existingIds = array_flip(collect($this->comments)->pluck('id')->all());
        $newComments = collect($comments)->reject(fn ($c) => isset($existingIds[$c['id']]))->all();

        if (! empty($newComments)) {
            $this->comments = array_values(array_merge($this->comments, $newComments));
        }

        return $newComments;
    }

    private function saveSession(): void
    {
        app(SessionStateAction::class)->saveGlobalNote($this->repoPath, $this->globalComment, $this->projectId ?: null);
    }

    private function loadTrashedFiles(): void
    {
        if ($this->isCommitMode()) {
            $this->trashedFiles = [];

            return;
        }

        $this->trashedFiles = app(CleanExpiredTrashAction::class)->handle($this->projectId);
    }

    // endregion: Computed, Helpers & Persistence
};
?>

<div
    data-testid="review-component"
    x-data="{
        pendingSaves: 0,
        init() {
            const wireId = this.$root.getAttribute('wire:id');
            Livewire.hook('commit', ({ component, succeed, fail }) => {
                if (component.id !== wireId) return;
                this.pendingSaves++;
                const done = () => { this.pendingSaves--; };
                succeed(({ snapshot, effect }) => { done(); });
                fail(done);
            });
            window.addEventListener('beforeunload', (e) => {
                if (this.pendingSaves > 0) e.preventDefault();
            });
        },
        activeFile: null,
        reviewedFiles: @js((object) collect($sourceFiles)->filter(fn($f) => array_key_exists($f['path'], $reviewedFiles))->pluck('id')->flip()->map(fn() => true)->all()),
        hideReviewed: false,
        fileFilter: '',
        sourceFileEntries: @js(collect($sourceFiles)->map(fn($f) => ['id' => $f['id'], 'path' => $f['path']])->values()->all()),
        sidebarWidth: $store.settings.sidebarWidth,
        resizing: false,
        remoteMenu: { open: false, x: 0, y: 0, projectSlug: '', type: '', params: {}, label: '' },
        showRemoteMenu($event) {
            const d = $event.detail;
            const projectBranch = @js($projectBranch);
            const diffFrom = @js($diffFrom);
            const diffTo = @js($diffTo);
            const refNew = diffTo || projectBranch || 'HEAD';
            const refOld = diffTo !== null ? diffFrom : (projectBranch || 'HEAD');
            const pathOld = d.oldPath || d.filePath;
            let type, params, label;
            if (d.target === 'file') {
                type = 'file';
                params = { ref: refNew, path: d.filePath };
                label = 'file';
            } else {
                type = 'line';
                params = {
                    ref: d.side === 'old' ? refOld : refNew,
                    path: d.side === 'old' ? pathOld : d.filePath,
                    start: d.start,
                    end: d.end,
                };
                label = (d.end === null || d.end === d.start) ? 'line ' + d.start : 'lines ' + d.start + '-' + d.end;
            }
            const margin = 8;
            const menuW = 220;
            const menuH = 80;
            this.remoteMenu = {
                open: true,
                x: Math.min(d.clientX, window.innerWidth - menuW - margin),
                y: Math.min(d.clientY, window.innerHeight - menuH - margin),
                projectSlug: @js($projectSlug),
                type, params, label,
            };
        },
        closeRemoteMenu() { this.remoteMenu.open = false; },
        get reviewedCount() {
            return Object.values(this.reviewedFiles).filter(Boolean).length;
        },
        fileMatchesFilter(path, fileId) {
            if (this.fileFilter !== '' && !path.toLowerCase().includes(this.fileFilter.toLowerCase())) return false;
            if (this.hideReviewed && this.reviewedFiles[fileId]) return false;
            return true;
        },
        scrollToFile(id) {
            this.activeFile = id;
            this.$dispatch('expand-file', { id });
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        startResize(e) {
            this.resizing = true;
            const startX = e.clientX;
            const startWidth = this.sidebarWidth;
            const aside = this.$refs.sidebar;
            const main = aside.parentElement.querySelector('main');
            let raf = null;
            let currentWidth = startWidth;

            // Float sidebar above main so diff DOM never reflows during drag
            aside.style.position = 'fixed';
            aside.style.left = '0';
            aside.style.zIndex = '40';
            aside.style.willChange = 'width';
            main.style.marginLeft = startWidth + 'px';
            document.body.classList.add('cursor-col-resize', 'select-none');

            const onMove = (e) => {
                currentWidth = Math.min(600, Math.max(200, startWidth + e.clientX - startX));
                if (raf) return;
                raf = requestAnimationFrame(() => {
                    aside.style.width = currentWidth + 'px';
                    raf = null;
                });
            };
            const onUp = () => {
                if (raf) { cancelAnimationFrame(raf); raf = null; }
                aside.style.position = '';
                aside.style.left = '';
                aside.style.zIndex = '';
                aside.style.willChange = '';
                main.style.marginLeft = '';
                this.resizing = false;
                this.sidebarWidth = currentWidth;
                document.body.classList.remove('cursor-col-resize', 'select-none');
                $store.settings.sidebarWidth = currentWidth;
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }
    }"
    @file-reviewed-changed.window="reviewedFiles[$event.detail.id] = $event.detail.reviewed"
    @reset-reviewed-files.window="reviewedFiles = {}"
    @show-remote-menu.window="showRemoteMenu($event)"
    @copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.text).then(() => {
            if ($event.detail.toast) Flux.toast({ text: $event.detail.toast, variant: 'success' });
        }).catch(() => {});
    "
    @keydown.window="
        if ($event.target.tagName === 'TEXTAREA' || $event.target.tagName === 'INPUT') {
            if ($event.key === 'Escape' && !$event.target.closest('[data-comment-form]')) { fileFilter = ''; $event.target.blur(); $event.preventDefault(); }
            return;
        }
        if ($event.key === '/') { $refs.fileFilterInput?.focus(); $event.preventDefault(); }
        if ($event.shiftKey && $event.key === 'C') { $store.settings.collapseAll = true; $dispatch('collapse-all-files'); $event.preventDefault(); }
        if ($event.shiftKey && $event.key === 'E') { $store.settings.collapseAll = false; $dispatch('expand-all-files'); $event.preventDefault(); }
        @if($commitInfo)
            if ($event.key === '[' && @js($commitInfo['prevHash'])) { Livewire.navigate('/p/{{ $projectSlug }}/c/' + @js($commitInfo['prevHash'])); $event.preventDefault(); }
            if ($event.key === ']' && @js($commitInfo['nextHash'])) { Livewire.navigate('/p/{{ $projectSlug }}/c/' + @js($commitInfo['nextHash'])); $event.preventDefault(); }
        @endif
    "
>
    @if($hasRemote)
        {{-- Shared remote-link context menu (one instance per review-page) --}}
        <template x-teleport="body">
            <div
                x-show="remoteMenu.open"
                x-cloak
                x-transition.opacity.duration.75ms
                @click.outside="closeRemoteMenu()"
                @keydown.escape.window="closeRemoteMenu()"
                @click="closeRemoteMenu()"
                class="fixed z-[100] min-w-[200px] py-1 rounded-md border border-gh-border bg-gh-surface shadow-lg"
                :style="`left:${remoteMenu.x}px; top:${remoteMenu.y}px`"
            >
                @native
                    <button
                        type="button"
                        @click.stop="$wire.openRemote(remoteMenu.projectSlug, remoteMenu.type, remoteMenu.params); closeRemoteMenu()"
                        class="w-full text-left px-3 py-1.5 text-xs font-mono text-gh-text hover:bg-gh-border/40 flex items-center gap-2 cursor-pointer"
                    >
                        <flux:icon icon="arrow-top-right-on-square" variant="outline" class="!size-3.5 text-gh-muted" />
                        <span>Open </span><span x-text="remoteMenu.label"></span>
                    </button>
                @endnative
                <button
                    type="button"
                    @click.stop="$wire.copyRemoteLink(remoteMenu.projectSlug, remoteMenu.type, remoteMenu.params); closeRemoteMenu()"
                    class="w-full text-left px-3 py-1.5 text-xs font-mono text-gh-text hover:bg-gh-border/40 flex items-center gap-2 cursor-pointer"
                >
                    <flux:icon icon="link" variant="outline" class="!size-3.5 text-gh-muted" />
                    <span>Copy </span><span x-text="remoteMenu.label"></span><span> link</span>
                </button>
            </div>
        </template>
    @endif

    <div
        class="sticky top-0 z-50"
        x-data
        x-init="
            const update = () => document.documentElement.style.setProperty('--header-h', $el.offsetHeight + 'px');
            update();
            new ResizeObserver(update).observe($el);
        "
    >
        @native
            <livewire:update-banner />
        @endnative

        <header class="bg-gh-bg/80 backdrop-blur-sm border-b border-gh-border px-5 py-3.5 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div @if($hasRemote) x-data="contextMenu()" @contextmenu.prevent="openCtx($event)" @endif class="inline-flex">
                    @native
                        <livewire:project-picker :current-slug="$projectSlug" :project-name="$projectName" />
                    @else
                        <span class="font-display font-bold tracking-brutal-tight text-base">{{ $projectName }}</span>
                    @endnative
                    @if($hasRemote)
                        <x-remote-link-menu
                            :project-slug="$projectSlug"
                            type="repo"
                            label="repository"
                        />
                    @endif
                </div>
                @php
                    $shortFrom = $diffFrom === 'HEAD' ? 'HEAD' : substr($diffFrom, 0, 7);
                    $shortTo = $diffTo ? substr($diffTo, 0, 7) : null;
                    if ($diffTo === null && $diffFrom === 'HEAD') {
                        $selectionLabel = 'Working tree';
                        $selectionTitle = 'Working tree changes';
                    } elseif ($diffTo === null) {
                        $selectionLabel = 'WT · '.$shortFrom;
                        $selectionTitle = 'Working tree + commits through '.$diffFrom;
                    } elseif ($diffFrom === $diffTo.'^') {
                        $selectionLabel = $shortTo;
                        $selectionTitle = $commitInfo['message'] ?? $diffTo;
                    } else {
                        $selectionLabel = $shortFrom.'..'.$shortTo;
                        $selectionTitle = 'Range '.$diffFrom.'..'.$diffTo;
                    }
                @endphp
                @if($projectBranch)
                    <x-header-separator />
                    <livewire:branch-explorer
                        :repo-path="$repoPath"
                        :current-branch="$projectBranch"
                        :project-slug="$projectSlug"
                        :active-commit-hash="$diffTo"
                        :active-diff-from="$diffFrom"
                        :has-remote="$hasRemote"
                        :selection-label="$selectionLabel"
                        :selection-title="$selectionTitle"
                    />
                @endif
                <livewire:comments-drawer :repo-path="$repoPath" :project-id="$projectId ?: null" />
            </div>
            <div class="flex items-center gap-2.5 text-xs">
                {{-- Stats --}}
                <span class="font-mono text-gh-muted"
                    x-text="fileFilter === '' && !hideReviewed
                        ? '{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}'
                        : sourceFileEntries.filter(f => fileMatchesFilter(f.path, f.id)).length + '/{{ count($sourceFiles) }} files'"
                >{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}</span>
                <span class="font-mono text-gh-green">+{{ collect($sourceFiles)->sum('additions') }}</span>
                <span class="font-mono text-gh-red">-{{ collect($sourceFiles)->sum('deletions') }}</span>

                {{-- Reviewed progress --}}
                <div x-show="reviewedCount > 0" x-cloak class="flex items-center gap-1.5">
                    <span class="w-px h-3.5 bg-gh-border"></span>
                    <div class="flex flex-col items-center min-w-[2.5rem]">
                        <span data-testid="reviewed-counter" class="font-mono text-gh-muted" x-text="reviewedCount + '/{{ count($sourceFiles) }} reviewed'"></span>
                        <div class="w-full h-0.5 bg-gh-border/50 rounded-full overflow-hidden mt-0.5">
                            <div class="h-full bg-gh-green/70 rounded-full transition-all duration-300" :style="'width:' + Math.round(reviewedCount / {{ count($sourceFiles) }} * 100) + '%'"></div>
                        </div>
                    </div>
                </div>

                @if(count($reviewPairs) > 0)
                    <span class="font-mono text-xs text-gh-muted px-1.5 py-0.5 rounded border border-gh-border">{{ count($reviewPairs) }} {{ Str::plural('review', count($reviewPairs)) }}</span>
                @endif

                <span class="w-px h-4 bg-gh-border"></span>

                {{-- Hide reviewed toggle --}}
                <div x-show="reviewedCount > 0" x-cloak class="grid place-items-center">
                    <flux:button variant="ghost" size="sm" icon="eye-slash" icon:variant="outline"
                        tooltip="Hide reviewed"
                        class="col-start-1 row-start-1"
                        @click="hideReviewed = true"
                        x-show="!hideReviewed" />
                    <flux:button variant="ghost" size="sm" icon="eye" icon:variant="outline"
                        tooltip="Show all files"
                        class="col-start-1 row-start-1"
                        @click="hideReviewed = false"
                        x-show="hideReviewed" x-cloak />
                </div>

                {{-- Expand/Collapse toggle --}}
                <div class="grid place-items-center">
                    <flux:button variant="ghost" size="sm" icon="expand-all" icon:variant="outline"
                        tooltip="Expand all · ⇧E" aria-label="Expand all (⇧E)"
                        class="col-start-1 row-start-1"
                        @click="$store.settings.collapseAll = false; $dispatch('expand-all-files')"
                        x-show="$store.settings.collapseAll" x-cloak />
                    <flux:button variant="ghost" size="sm" icon="collapse-all" icon:variant="outline"
                        tooltip="Collapse all · ⇧C" aria-label="Collapse all (⇧C)"
                        class="col-start-1 row-start-1"
                        @click="$store.settings.collapseAll = true; $dispatch('collapse-all-files')"
                        x-show="!$store.settings.collapseAll" />
                </div>

                @if(! $this->isCommitMode())
                    <div data-testid="change-polling" x-data="{
                        hasChanges: false,
                        fingerprint: null,
                        polling: null,
                        async check() {
                            try {
                                const res = await fetch('/api/changes/{{ $projectId }}');
                                const data = await res.json();
                                if (this.fingerprint === null) {
                                    this.fingerprint = data.fingerprint;
                                } else if (data.fingerprint !== this.fingerprint) {
                                    this.hasChanges = true;
                                }
                            } catch {}
                        },
                        startPolling() {
                            this.check();
                            this.polling = setInterval(() => {
                                if (!document.hidden) this.check();
                            }, 60000);
                        },
                        refresh() {
                            window.location.reload();
                        }
                    }" x-init="startPolling(); $store.keymap.register('⌘R', () => refresh(), { allowInEditable: true })" @fingerprint-reset.window="fingerprint = null; hasChanges = false" class="relative flex items-center">
                        <flux:button variant="ghost" size="sm" icon="arrow-path" icon:variant="outline"
                            tooltip="Refresh · ⌘R" aria-label="Refresh · ⌘R" @click="refresh()" />
                        <span x-show="hasChanges" x-cloak
                            class="absolute -top-0.5 -right-0.5 flex h-2.5 w-2.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                        </span>
                    </div>
                @endif

                <span class="w-px h-4 bg-gh-border"></span>

                {{-- Settings --}}
                @if(! $this->isCommitMode())
                    <flux:dropdown position="bottom" align="end">
                        <flux:button variant="ghost" size="sm" icon="cog-6-tooth" icon:variant="outline"
                            aria-label="Settings" />
                        <flux:menu>
                            <flux:menu.item keep-open>
                                <flux:checkbox wire:model.live="respectGlobalGitignore" label="Global .gitignore" class="text-xs whitespace-nowrap" />
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif

                <livewire:theme-switcher />
            </div>
        </header>
    </div>

    {{-- Branch divergence banner + polling island (working-tree mode only) --}}
    @if(! $this->isCommitMode())
        <livewire:head-divergence-poller
            wire:key="head-divergence-poller-{{ $projectId }}-{{ $diffFrom }}-{{ $projectBranch }}"
            :repo-path="$repoPath"
            :target="$projectBranch"
        />

        @if($divergenceState === DivergenceState::Diverged)
            <div class="px-5 py-3 border-b border-gh-border" data-testid="divergence-banner-diverged">
                <flux:callout icon="arrow-path" variant="warning" inline>
                    <flux:callout.heading>Repo switched to <span class="font-mono">{{ $divergenceContext['currentBranch'] }}</span></flux:callout.heading>
                    <flux:callout.text>Still reviewing <span class="font-mono">{{ $divergenceContext['target'] }}</span>.</flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" variant="primary" wire:click="switchReviewToHead">
                            Switch review to <span class="font-mono ml-1">{{ $divergenceContext['currentBranch'] }}</span>
                        </flux:button>
                        <flux:button size="sm" variant="ghost" wire:click="keepReviewing">
                            Keep reviewing <span class="font-mono ml-1">{{ $divergenceContext['target'] }}</span>
                        </flux:button>
                    </x-slot>
                </flux:callout>
            </div>
        @elseif($divergenceState === DivergenceState::Detached)
            <div class="px-5 py-3 border-b border-gh-border" data-testid="divergence-banner-detached">
                <flux:callout icon="information-circle" variant="secondary" inline>
                    <flux:callout.heading>Repo detached at <span class="font-mono">{{ $divergenceContext['shortSha'] }}</span></flux:callout.heading>
                    <flux:callout.text>Still reviewing <span class="font-mono">{{ $divergenceContext['target'] }}</span>.</flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" variant="ghost" wire:click="dismissDetachedBanner">Dismiss</flux:button>
                    </x-slot>
                </flux:callout>
            </div>
        @elseif($divergenceState === DivergenceState::MissingTarget)
            <div class="px-5 py-3 border-b border-gh-border" data-testid="divergence-banner-missing">
                <flux:callout icon="exclamation-triangle" variant="danger" inline>
                    <flux:callout.heading>Review target <span class="font-mono">{{ $divergenceContext['target'] }}</span> no longer exists</flux:callout.heading>
                    <x-slot name="actions">
                        <flux:button size="sm" variant="primary" wire:click="switchReviewToHead">
                            Switch to <span class="font-mono ml-1">{{ $divergenceContext['currentBranch'] }}</span>
                        </flux:button>
                    </x-slot>
                </flux:callout>
            </div>
        @endif
    @endif

    {{-- Commit context bar --}}
    @if($commitInfo)
        <div data-testid="commit-context-bar" class="sticky top-[var(--header-h)] z-40 bg-gh-surface border-b border-gh-border px-5 py-2.5 flex items-center gap-3 text-xs" style="--commit-bar-h: 40px;">
            <flux:icon icon="code-bracket" variant="outline" class="text-gh-muted shrink-0" />
            <span class="font-mono text-xs text-gh-muted shrink-0 px-1.5 py-0.5 rounded border border-gh-border">{{ $commitInfo['shortHash'] }}</span>
            <span class="text-gh-text truncate font-medium">{{ $commitInfo['message'] }}</span>
            <span class="text-gh-muted shrink-0">{{ $commitInfo['author'] }}</span>
            <div class="ml-auto flex items-center gap-1 shrink-0">
                @if($commitInfo['prevHash'])
                    <flux:tooltip content="Previous commit ([)">
                        <flux:button aria-label="Previous commit" variant="ghost" size="xs" icon="chevron-left" icon:variant="outline"
                            onclick="Livewire.navigate('/p/{{ $projectSlug }}/c/{{ $commitInfo['prevHash'] }}')" />
                    </flux:tooltip>
                @endif
                @if($commitInfo['nextHash'])
                    <flux:tooltip content="Next commit (])">
                        <flux:button aria-label="Next commit" variant="ghost" size="xs" icon="chevron-right" icon:variant="outline"
                            onclick="Livewire.navigate('/p/{{ $projectSlug }}/c/{{ $commitInfo['nextHash'] }}')" />
                    </flux:tooltip>
                @endif
                <flux:tooltip content="Back to working directory">
                    <flux:button aria-label="Back to working directory" variant="ghost" size="xs" icon="x-mark" icon:variant="outline"
                        onclick="Livewire.navigate('/p/{{ $projectSlug }}')" />
                </flux:tooltip>
            </div>
        </div>
    @endif

    <div class="flex">
        {{-- Sidebar --}}
        <aside class="shrink-0 sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] overflow-y-auto border-r border-gh-border bg-gh-bg hidden lg:block" :style="{ width: sidebarWidth + 'px' }" x-ref="sidebar">
            <div class="p-4">
                @if(! $this->isCommitMode() && count($reviewPairs) > 0)
                    <div class="flex items-center justify-between mb-3">
                        <span class="section-label text-gh-muted">Reviews</span>
                        @if(count($reviewPairs) > 1)
                            <button class="text-gh-muted hover:text-red-400 transition-colors"
                                @click="if (confirm('Delete all review files?')) $wire.deleteAllReviewPairs()">
                                <flux:icon icon="trash" variant="outline" class="!size-4" />
                            </button>
                        @endif
                    </div>
                    @foreach($reviewPairs as $pair)
                        <div class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-colors text-gh-text">
                            <span class="text-[10px] font-mono font-medium text-purple-500 dark:text-purple-400 shrink-0">R</span>
                            <button @click="scrollToFile('{{ $pair['id'] }}')" class="truncate text-left font-mono" title="{{ $pair['basename'] }}">
                                {{ $pair['displayName'] }}
                            </button>
                            <button class="opacity-0 group-hover:opacity-100 transition-opacity text-red-400 hover:text-red-300 shrink-0 ml-auto"
                                @click="if (confirm('Delete this review?')) $wire.deleteReviewPair('{{ $pair['basename'] }}')">
                                <flux:icon icon="trash" variant="outline" class="!size-4" />
                            </button>
                        </div>
                    @endforeach
                    <div class="border-b border-gh-border my-3"></div>
                @endif

                <span class="section-label text-gh-muted mb-3 block">Files</span>
                <flux:input
                    x-model.debounce.150ms="fileFilter"
                    placeholder="Filter files..."
                    icon="magnifying-glass"
                    icon:variant="outline"
                    clearable
                    kbd="/"
                    size="sm"
                    variant="filled"
                    class="mb-3"
                    x-ref="fileFilterInput"
                    @keydown.escape="fileFilter = ''; $el.blur()"
                />
                @foreach($sourceFiles as $file)
                    @php
                        [$badgeColor, $badgeLabel] = match($file['status']) {
                            'added' => ['green', 'A'],
                            'deleted' => ['red', 'D'],
                            'renamed' => ['yellow', 'R'],
                            'commented' => ['zinc', 'C'],
                            default => ['yellow', 'M'],
                        };
                    @endphp
                    <div
                        x-show="fileMatchesFilter(@js($file['path']), '{{ $file['id'] }}')"
                        class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-colors"
                        :class="activeFile === '{{ $file['id'] }}' ? 'bg-gh-link/10 text-gh-link' : 'text-gh-muted'"
                    >
                        <button @click="scrollToFile('{{ $file['id'] }}')" class="flex items-center gap-2.5 min-w-0 flex-1">
                            <span class="font-mono font-medium shrink-0 {{ match($badgeLabel) { 'A' => 'text-gh-green', 'D' => 'text-gh-red', 'C' => 'text-gh-muted', default => 'text-amber-500 dark:text-amber-400' } }}">{{ $badgeLabel }}</span>
                            @if($file['isSymlink'] ?? false)
                                <flux:icon icon="link" variant="outline" class="!size-3 text-gh-muted shrink-0" aria-hidden="true" />
                            @endif
                            <span class="truncate font-mono" title="{{ $file['path'] }}{{ ($file['isSymlink'] ?? false) ? ' -> ' . $file['symlinkTarget'] : '' }}{{ ($file['lastModified'] ?? null) ? "\nModified " . $file['lastModified'] : '' }}">{{ $file['path'] }}</span>
                        </button>
                        <span class="shrink-0 size-3.5 flex items-center justify-center">
                            <flux:icon icon="check" variant="outline"
                                class="!size-3.5 text-gh-green {{ array_key_exists($file['path'], $reviewedFiles) ? '' : 'invisible' }}"
                                ::class="{ 'invisible': !reviewedFiles['{{ $file['id'] }}'] }" />
                        </span>
                        <span class="shrink-0 size-3.5 flex items-center justify-center">
                            @if(! $this->isCommitMode() && $file['status'] !== 'commented')
                                <button
                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-gh-muted hover:text-gh-text data-loading:pointer-events-none data-loading:opacity-50"
                                    title="Discard changes"
                                    wire:click.stop="discardFileChanges('{{ $file['id'] }}')"
                                >
                                    <flux:icon icon="arrow-uturn-left" variant="outline" class="!size-3.5" />
                                </button>
                            @endif
                        </span>
                        <span class="ml-auto flex gap-1.5 shrink-0 font-mono">
                            @if($file['additions'] > 0)
                                <span class="text-gh-green">+{{ $file['additions'] }}</span>
                            @endif
                            @if($file['deletions'] > 0)
                                <span class="text-gh-red">-{{ $file['deletions'] }}</span>
                            @endif
                        </span>
                    </div>
                @endforeach
                @if(! empty($trashedFiles))
                    <div class="border-t border-gh-border mt-3 pt-3">
                        <span class="section-label text-gh-muted mb-3 block">Trash</span>
                        @foreach($trashedFiles as $trashed)
                            <div class="w-full px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2 group transition-colors"
                                x-data="{ expiresAt: {{ $trashed['expires_at'] ? \Carbon\Carbon::parse($trashed['expires_at'])->getTimestampMs() : 0 }}, remaining: '' }"
                                x-init="
                                    const update = () => {
                                        const ms = expiresAt - Date.now();
                                        if (ms <= 0) { remaining = 'expired'; clearInterval($el._iv); return; }
                                        const m = Math.ceil(ms / 60000);
                                        remaining = m < 1 ? '< 1m' : m + 'm';
                                    };
                                    update();
                                    $el._iv = setInterval(update, 15000);
                                "
                            >
                                <span class="font-mono text-xs text-gh-muted truncate flex-1" title="{{ $trashed['file_path'] }}">{{ basename($trashed['file_path']) }}</span>
                                <span class="text-[10px] text-gh-muted tabular-nums" x-text="remaining"></span>
                                <button @click="$wire.restoreDiscardedFile({{ $trashed['id'] }})" title="Restore"
                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-gh-green hover:text-green-400 shrink-0">
                                    <flux:icon icon="arrow-uturn-left" variant="outline" class="!size-3.5" />
                                </button>
                                <button @click="if (confirm('Permanently delete?')) $wire.permanentlyDeleteTrashed({{ $trashed['id'] }})" title="Delete permanently"
                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-red-400 hover:text-red-300 shrink-0">
                                    <flux:icon icon="trash" variant="outline" class="!size-3.5" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </aside>
        <div data-testid="sidebar-resize-handle" class="group/resize hidden lg:flex sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] w-0 cursor-col-resize items-center justify-center z-10 shrink-0"
            style="padding: 0 6px; margin: 0 -6px;"
            @mousedown="startResize($event)"
            @dblclick="sidebarWidth = 288; $store.settings.sidebarWidth = 288">
            <div class="absolute inset-y-0 w-px bg-transparent group-hover/resize:bg-gh-muted/40 transition-colors"></div>
            <div class="absolute px-1 py-1.5 rounded-full bg-gh-surface border border-gh-border shadow-sm opacity-0 group-hover/resize:opacity-100 transition-opacity pointer-events-none flex flex-col items-center gap-[3px]">
                <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
                <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
                <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
            </div>
        </div>

        {{-- Main content --}}
        <main class="flex-1 min-w-0 pb-24" :class="resizing && 'pointer-events-none'" style="contain: inline-size layout style">
            @if($gitError)
                <div class="flex items-center justify-center h-[60vh]">
                    <div class="text-center max-w-lg">
                        <p class="rfa-logo text-3xl text-red-400/30 mb-4">!</p>
                        <h2 class="font-semibold tracking-brutal text-lg mb-2">Git error</h2>
                        <p class="font-mono text-xs text-gh-muted leading-relaxed">{{ $gitError }}</p>
                    </div>
                </div>
            @elseif(empty($files))
                <div class="flex items-center justify-center h-[60vh]">
                    <div class="text-center">
                        <p class="rfa-logo text-5xl text-gh-muted/20 mb-6">rfa</p>
                        @if($this->isCommitMode())
                            <h2 class="font-semibold tracking-brutal text-lg mb-2">No file changes in this commit</h2>
                            <p class="text-sm text-gh-muted">This commit has no diff (empty or merge commit)</p>
                        @else
                            <h2 class="font-semibold tracking-brutal text-lg mb-2">Working tree is clean</h2>
                            <p class="text-sm text-gh-muted">Edit files to see them here</p>
                        @endif
                    </div>
                </div>
            @else
                {{-- Review Pairs (working directory mode only) --}}
                @if(! $this->isCommitMode())
                    @foreach($reviewPairs as $pair)
                        <div id="{{ $pair['id'] }}" class="border-b border-gh-border" x-data="{ collapsed: true }">
                            <div class="sticky top-[var(--header-h)] z-10 bg-gh-surface/80 backdrop-blur-sm border-b border-gh-border px-5 py-2.5 flex items-center gap-2.5">
                                <button @click="collapsed = !collapsed" class="text-gh-muted hover:text-gh-text transition-colors">
                                    <flux:icon icon="chevron-down" variant="outline" x-show="!collapsed" />
                                    <flux:icon icon="chevron-right" variant="outline" x-show="collapsed" x-cloak />
                                </button>
                                <span class="text-[10px] font-mono font-medium text-purple-500 dark:text-purple-400 shrink-0">R</span>
                                <span class="font-mono text-sm truncate">{{ $pair['displayName'] }}</span>
                                @if($pair['jsonFile'])
                                    <span class="text-[10px] font-mono text-gh-muted">.json</span>
                                @endif
                                @if($pair['mdFile'])
                                    <span class="text-[10px] font-mono text-gh-muted">.md</span>
                                @endif
                                <span class="ml-auto">
                                    <flux:button variant="ghost" size="sm" icon="trash" icon:variant="outline"
                                        @click="if (confirm('Delete this review?')) $wire.deleteReviewPair('{{ $pair['basename'] }}')" />
                                </span>
                            </div>
                            <div x-show="!collapsed" x-collapse.duration.150ms>
                                @if($pair['jsonFile'])
                                    <livewire:diff-file
                                        :key="$pair['jsonFile']['id']"
                                        :file="$pair['jsonFile']"
                                        :load-delay="0"
                                        :file-comments="$this->groupedComments[$pair['jsonFile']['id']] ?? []"
                                        :is-reviewed="array_key_exists($pair['jsonFile']['path'], $reviewedFiles)"
                                        :repo-path="$repoPath"
                                        :project-id="$projectId"
                                        :has-remote="$hasRemote"
                                        :diff-from="$diffFrom"
                                        :diff-to="$diffTo"
                                    />
                                @endif
                                @if($pair['mdFile'])
                                    <livewire:diff-file
                                        :key="$pair['mdFile']['id']"
                                        :file="$pair['mdFile']"
                                        :load-delay="0"
                                        :file-comments="$this->groupedComments[$pair['mdFile']['id']] ?? []"
                                        :is-reviewed="array_key_exists($pair['mdFile']['path'], $reviewedFiles)"
                                        :repo-path="$repoPath"
                                        :project-id="$projectId"
                                        :has-remote="$hasRemote"
                                        :diff-from="$diffFrom"
                                        :diff-to="$diffTo"
                                    />
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif

                {{-- Source Files --}}
                @php $singleFile = count($sourceFiles) === 1 && count($reviewPairs) === 0; @endphp
                @foreach($sourceFiles as $file)
                    <div id="{{ $file['id'] }}" class="border-b border-gh-border" x-show="fileMatchesFilter(@js($file['path']), '{{ $file['id'] }}')">
                        <livewire:diff-file
                            :key="$file['id']"
                            :file="$file"
                            :load-delay="(int) (floor($loop->index / 15) * 100)"
                            :file-comments="$this->groupedComments[$file['id']] ?? []"
                            :is-reviewed="array_key_exists($file['path'], $reviewedFiles)"
                            :single-file="$singleFile"
                            :repo-path="$repoPath"
                            :project-id="$projectId"
                            :has-remote="$hasRemote"
                            :diff-from="$diffFrom"
                            :diff-to="$diffTo"
                        />
                    </div>
                @endforeach
            @endif
        </main>
    </div>

    {{-- Undo toast --}}
    @include('livewire.undo-toast')

    {{-- Submit bar --}}
    @include('livewire.submit-bar')
</div>

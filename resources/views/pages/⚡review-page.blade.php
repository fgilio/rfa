<?php

use App\Actions\AddCommentAction;
use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\CleanExpiredTrashAction;
use App\Actions\DeleteCommentAction;
use App\Actions\DeleteReviewFilesAction;
use App\Actions\DeleteTrashedFileAction;
use App\Actions\DiscardFileChangesAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetCurrentHeadAction;
use App\Actions\GetFileListAction;
use App\Actions\GroupReviewFilesAction;
use App\Actions\IsSinceBaseViewAction;
use App\Actions\LinkExternalPathAction;
use App\Actions\LoadCommitMetadataAction;
use App\Actions\PersistProjectViewAction;
use App\Actions\ResolveCommitAction;
use App\Actions\ResolveProjectAction;
use App\Actions\ResolveRangeAction;
use App\Actions\ResolveRangeToWorkingAction;
use App\Actions\ResolveStartupRouteAction;
use App\Actions\RestoreDiscardedFileAction;
use App\Actions\ScanReviewFilesAction;
use App\Actions\SessionStateAction;
use App\Actions\ToggleReviewedAction;
use App\Actions\UnlinkExternalPathAction;
use App\Actions\UpdateCommentAction;
use App\Actions\UpdateProjectSettingAction;
use App\Concerns\InteractsWithRemoteLinks;
use App\DTOs\CurrentHeadResult;
use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\Enums\DiffSide;
use App\Enums\DivergenceState;
use App\Enums\GitRef;
use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use App\Exceptions\GitCommandException;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

use function Illuminate\Support\defer;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithRemoteLinks;

    /** Undo-toast `type` for the "marked file as reviewed" action. */
    private const UNDO_TYPE_MARK_REVIEWED = 'mark-reviewed';

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

    /**
     * MRU-ordered file IDs marked as reviewed during the current "Hide reviewed" session.
     * Surfaced as a sticky "Recently reviewed" sidebar group so the user can un-mark
     * without leaving Hide-reviewed mode. Capped at 5; reset when the user shows all files.
     *
     * @var array<int, string>
     */
    public array $recentlyReviewedIds = [];

    public ?string $activeFileId = null;

    /** @var array<int, array<string, mixed>> */
    public array $trashedFiles = [];

    public bool $respectGlobalGitignore = true;

    public ?string $globalGitignorePath = null;

    public string $defaultBaseBranch = '';

    /** @var list<array{label: string, path: string}> */
    public array $externalPaths = [];

    #[Locked]
    public string $diffFrom = 'HEAD';

    #[Locked]
    public ?string $diffTo = null;

    /** True when the active rangeToWorking diff equals `default_base_branch..HEAD..working`. */
    #[Locked]
    public bool $isSinceBaseView = false;

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

        Cache::put('rfa.active-project-id', $this->projectId, now()->addDay());

        if (config('nativephp-internal.running')) {
            \Native\Desktop\Facades\Window::get('main')->title("rfa - {$this->projectName}");
        }

        $this->respectGlobalGitignore = $project['respect_global_gitignore'] ?? true;
        $this->globalGitignorePath = $project['global_gitignore_path'] ?: null;
        $this->defaultBaseBranch = (string) ($project['default_base_branch'] ?? '');
        // Stored shape is `[{label, path}]` because every write goes through
        // Link/UnlinkExternalPathAction; trust it without re-normalizing.
        $this->externalPaths = (array) ($project['external_paths'] ?? []);

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

        $this->isSinceBaseView = $this->detectSinceBaseView();

        $this->rehydrateForTarget();
        $this->checkHeadDivergence();

        $this->persistCurrentView($hash, $from, $to, $ref, $baseRef, $rangeFromWorking);
    }

    /**
     * Persist the (mode, kind, from, to) shape so that re-entering this
     * project — via the picker, the home redirect, or a deep-link — puts
     * the user back on the same surface.
     *
     * Kind is derived from the arrival shape (which mount branch fired)
     * rather than from `$diffFrom`/`$diffTo`, because resolved SHAs lose
     * the original "single commit vs explicit range" distinction.
     */
    private function persistCurrentView(?string $hash, ?string $from, ?string $to, ?string $ref, ?string $baseRef, ?string $rangeFromWorking): void
    {
        $kind = match (true) {
            $hash !== null => LastViewKind::Commit,
            $from !== null && $to !== null => LastViewKind::Range,
            $rangeFromWorking !== null => $this->isSinceBaseView ? LastViewKind::SinceBase : LastViewKind::RangeToWorking,
            $ref !== null && $baseRef !== null => LastViewKind::Range,
            default => LastViewKind::WorkingTree,
        };

        $projectId = $this->projectId;
        $repoPath = $this->repoPath;
        $diffFrom = $this->diffFrom;
        $diffTo = $this->diffTo;

        // Run after the response is sent: the persisted view is only consumed
        // on the next navigation, so making the user wait for the UPSERT here
        // would be needless mount latency.
        defer(static function () use ($projectId, $repoPath, $kind, $diffFrom, $diffTo) {
            app(PersistProjectViewAction::class)->handle(
                $projectId,
                $repoPath,
                LastViewMode::Review,
                $kind,
                $kind === LastViewKind::WorkingTree || $kind === LastViewKind::Commit ? null : $diffFrom,
                $kind === LastViewKind::WorkingTree ? null : $diffTo,
            );
        });
    }

    private function rehydrateForTarget(): void
    {
        $this->cachedTarget = null;
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

    /**
     * True when the current rangeToWorking view is exactly `base..working` for
     * the project's configured base branch. Drives the "Since {base}" header
     * label so the user can tell at a glance which view they're in.
     */
    private function detectSinceBaseView(): bool
    {
        if ($this->diffTo !== null || $this->defaultBaseBranch === '' || $this->diffFrom === 'HEAD') {
            return false;
        }

        return app(IsSinceBaseViewAction::class)
            ->handle($this->repoPath, $this->defaultBaseBranch, $this->diffFrom);
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

    public function updatedDefaultBaseBranch(): void
    {
        $value = trim($this->defaultBaseBranch);
        $this->defaultBaseBranch = $value;

        app(UpdateProjectSettingAction::class)->handle($this->projectId, [
            'default_base_branch' => $value === '' ? null : $value,
        ]);

        $this->isSinceBaseView = $this->detectSinceBaseView();
    }

    public function addExternalPath(): void
    {
        $picked = app(\Native\Desktop\Dialog::class)
            ->title('Link External Folder')
            ->folders()
            ->open();

        if (! is_string($picked) || $picked === '') {
            $this->skipRender();

            return;
        }

        $previousCount = count($this->externalPaths);
        $updated = app(LinkExternalPathAction::class)->handle($this->projectId, $picked);
        if ($updated === null) {
            Flux::toast(variant: 'danger', text: 'Could not link folder: '.basename($picked));
            $this->skipRender();

            return;
        }

        if (count($updated) === $previousCount) {
            Flux::toast(text: basename($picked).' is already linked');
            $this->skipRender();

            return;
        }

        $this->externalPaths = $updated;
        $this->reloadAfterExternalPathsChange();
        Flux::toast(variant: 'success', text: 'Linked '.basename($picked));
    }

    public function removeExternalPath(int $index): void
    {
        $removed = $this->externalPaths[$index]['label'] ?? null;

        $updated = app(UnlinkExternalPathAction::class)->handle($this->projectId, $index);
        if ($updated === null) {
            $this->skipRender();

            return;
        }

        $this->externalPaths = $updated;
        $this->reloadAfterExternalPathsChange();

        if ($removed !== null) {
            Flux::toast(text: 'Unlinked '.$removed);
        }
    }

    private function reloadAfterExternalPathsChange(): void
    {
        $this->refreshFileList();

        $target = $this->buildDiffTarget();
        $session = app(SessionStateAction::class)->handle($this->repoPath, $this->files, $this->projectId, $target);
        $this->comments = $session['comments'];
        $this->reviewedFiles = $session['reviewedFiles'];

        if (! empty($session['orphanedPaths'])) {
            $this->injectOrphanedFiles($session['orphanedPaths']);
        }
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

    public function softRefresh(): void
    {
        $before = $this->fileFingerprints($this->files);

        $this->rehydrateForTarget();
        $divergenceChanged = $this->refreshDivergenceState();

        $after = $this->fileFingerprints($this->files);
        $changedCount = count(array_diff_assoc($after, $before))
            + count(array_diff_key($before, $after));

        $this->dispatch('fingerprint-reset');
        $this->dispatch('refresh-completed', changedCount: $changedCount);

        if ($changedCount === 0 && ! $divergenceChanged) {
            $this->skipRender();
        }
    }

    /**
     * Per-file change signature for softRefresh's skipRender heuristic.
     *
     * Uses raw mtime + byte size — not the human-readable `lastModified` /
     * `fileSize` strings, because those bucket aggressively (`diffForHumans`
     * short-form rounds to whole seconds against an ever-advancing "now",
     * `Number::fileSize` rounds to a precision-1 unit). With those, two
     * rapid in-place WC edits of the same byte count produce identical
     * fingerprints — softRefresh thinks nothing changed, latches
     * skipRender, and the diff stays stale despite the cache being cleared
     * upstream. `additions/deletions` from numstat are also too coarse on
     * their own in 1commit+WC mode: an in-place edit on a line already
     * modified by the pinned commit doesn't change either count.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, string>
     */
    private function fileFingerprints(array $files): array
    {
        return collect($files)
            ->mapWithKeys(fn (array $f) => [
                (string) $f['id'] => sprintf(
                    '%s|%s|%s|%s|%s',
                    $f['status'] ?? '',
                    $f['additions'] ?? 0,
                    $f['deletions'] ?? 0,
                    $f['mtime'] ?? '',
                    $f['byteSize'] ?? '',
                ),
            ])
            ->all();
    }

    // endregion: Initialization & Diff Context

    // region: Branch Divergence

    #[On('head-divergence-transitioned')]
    public function checkHeadDivergence(): void
    {
        if (! $this->refreshDivergenceState()) {
            $this->skipRender();
        }
    }

    /**
     * HEAD advanced on the same branch the user is reviewing — typically
     * because they just committed. Reload the file list so the diff reflects
     * the new commit. Commit/range modes pin both endpoints, so a HEAD move
     * cannot affect what's shown; skip render in that case.
     */
    #[On('head-advanced-on-branch')]
    public function refreshAfterHeadAdvance(): void
    {
        if ($this->isCommitMode()) {
            $this->skipRender();

            return;
        }

        $this->softRefresh();
    }

    /**
     * Recompute divergence state. Returns true if state changed since the last
     * check (i.e. the caller should render), false when the caller can skip.
     * Kept separate from `checkHeadDivergence()` so callers like `softRefresh`
     * can update divergence without latching `skipRender()` onto a response
     * that still needs to morph because files did change.
     */
    private function refreshDivergenceState(): bool
    {
        if ($this->isCommitMode()) {
            $changed = ! $this->divergenceChecked;
            $this->divergenceChecked = true;

            return $changed;
        }

        $before = [$this->divergenceState, $this->divergenceContext, $this->dismissedAtHead, $this->projectBranch];

        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->projectBranch ?: null);
        $this->resolveDivergenceState($head);

        $after = [$this->divergenceState, $this->divergenceContext, $this->dismissedAtHead, $this->projectBranch];

        $changed = ! $this->divergenceChecked || $before !== $after;
        $this->divergenceChecked = true;

        return $changed;
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
                    'origin_ref' => $c['originRef'] ?? GitRef::Working->value,
                    'file_path' => $c['file'] ?? '',
                    'side' => $c['side'] ?? DiffSide::Right->value,
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
            Flux::toast(variant: 'danger', text: 'Discard failed for '.basename($file['path']).': '.$message);
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
            $message = $e instanceof GitCommandException ? $e->stderr : $e->getMessage();
            Flux::toast(variant: 'danger', text: 'Restore failed: '.$message);
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
            self::UNDO_TYPE_MARK_REVIEWED => $this->unmarkReviewed($payload['filePaths'] ?? []),
            default => null,
        };
    }

    /** @param array<int, string> $filePaths */
    public function unmarkReviewed(array $filePaths): void
    {
        if (empty($filePaths)) {
            return;
        }

        // Delete by path directly so the undo survives a refresh: if content-hash drift
        // dropped the entry from $reviewedFiles between mark and undo, the action's
        // toggle would no-op and the DB row would linger despite the toast firing.
        \App\Models\ReviewedFile::query()
            ->forProjectOrRepo($this->projectId ?: null, $this->repoPath)
            ->whereIn('file_path', $filePaths)
            ->delete();

        foreach ($filePaths as $filePath) {
            unset($this->reviewedFiles[$filePath]);
        }

        $pathToId = array_column($this->files, 'id', 'path');
        $revertedIds = array_values(array_filter(array_map(
            fn (string $path): ?string => $pathToId[$path] ?? null,
            $filePaths,
        )));

        $this->recentlyReviewedIds = array_values(array_filter(
            $this->recentlyReviewedIds,
            fn (string $id): bool => ! in_array($id, $revertedIds, true),
        ));

        if (! empty($revertedIds)) {
            $this->dispatch('reviewed-files-reverted', fileIds: $revertedIds);
        }

        $this->skipRender();
    }

    // endregion: Trash & Discard

    // region: Review State & Export

    #[On('toggle-reviewed')]
    public function toggleReviewed(string $filePath): void
    {
        $wasReviewed = array_key_exists($filePath, $this->reviewedFiles);

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

        $isNowReviewed = array_key_exists($filePath, $this->reviewedFiles);
        // Source-file lookup (not $this->files) so review-pair artifacts
        // (.json/.md) never consume a slot in the "Recently reviewed" group —
        // that group renders from sourceFiles only.
        $fileId = collect($this->sourceFiles)->firstWhere('path', $filePath)['id'] ?? null;

        if (! $wasReviewed && $isNowReviewed) {
            if ($fileId !== null) {
                $this->recentlyReviewedIds = array_slice(
                    array_values(array_unique(array_merge([$fileId], $this->recentlyReviewedIds))),
                    0, 5,
                );
            }

            $this->dispatch(
                'undo-available',
                type: self::UNDO_TYPE_MARK_REVIEWED,
                payload: ['filePaths' => [$filePath]],
                message: 'Marked '.basename($filePath).' as reviewed',
            );
        } elseif ($wasReviewed && ! $isNowReviewed && $fileId !== null) {
            $this->recentlyReviewedIds = array_values(array_filter(
                $this->recentlyReviewedIds,
                fn (string $id): bool => $id !== $fileId,
            ));

            // Single un-mark transition uses the same broadcast as bulk undo so the
            // sidebar's reviewedFiles map and DiffFile's `reviewed` mirror flip in lockstep
            // — callers (e.g. the "Recently reviewed" group) don't have to dual-dispatch.
            $this->dispatch('reviewed-files-reverted', fileIds: [$fileId]);
        }

        $this->skipRender();
    }

    public function clearRecentlyReviewed(): void
    {
        $this->recentlyReviewedIds = [];
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

    public function startNewReview(): void
    {
        $this->submitted = false;
        $this->exportResult = null;
    }

    // endregion: Review State & Export

    // region: Computed, Helpers & Persistence

    /** @return array<string, array<int, array<string, mixed>>> */
    #[Computed]
    public function groupedComments(): array
    {
        return collect($this->comments)->groupBy('fileId')->map(fn ($group) => $group->values()->all())->all();
    }

    /** Drives the amber dot on the Context side of the mode-toggle. */
    #[Computed]
    public function hasContextActivity(): bool
    {
        return \App\Models\Comment::forProjectOrRepo($this->projectId ?: null, $this->repoPath)
            ->fromContext()
            ->unsubmitted()
            ->exists();
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
        $this->sourceFiles = app(GroupReviewFilesAction::class)->handle($this->files);
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

@assets
<script src="/js/diff-file.js"></script>
@endassets

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
        filesById: @js(collect($sourceFiles)->mapWithKeys(fn($f) => [$f['id'] => [
            'path' => $f['path'],
            'badgeLabel' => match($f['status']) {
                'added' => 'A',
                'deleted' => 'D',
                'renamed' => 'R',
                'commented' => 'C',
                default => 'M',
            },
            'badgeClass' => match($f['status']) {
                'added' => 'text-gh-green',
                'deleted' => 'text-gh-red',
                'commented' => 'text-gh-muted',
                default => 'text-gh-attention',
            },
        ]])->all()),
        remoteMenu: { open: false, x: 0, y: 0, projectSlug: '', type: '', params: {}, label: '', disabled: false, disabledReason: '' },
        showRemoteMenu($event) {
            const d = $event.detail;
            const margin = 8;

            if (d.target === 'direct') {
                const menuW = 220;
                const menuH = 80;
                this.remoteMenu = {
                    open: true,
                    x: Math.min(d.clientX, window.innerWidth - menuW - margin),
                    y: Math.min(d.clientY, window.innerHeight - menuH - margin),
                    projectSlug: d.projectSlug || @js($projectSlug),
                    type: d.type,
                    params: d.params || {},
                    label: d.label || 'on remote',
                    disabled: false,
                    disabledReason: '',
                };

                return;
            }

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
            // Old-side line links always resolve (refOld is where the file existed).
            // For new-side links we only disable when we're sure: pure working-tree
            // mode for `added`, commit/range mode for `deleted`. /rw/{from} mixes
            // working tree and committed history, so we can't tell which side a
            // status belongs to — leave it enabled rather than mis-disable.
            const isWorkingTreeOnly = diffTo === null && diffFrom === 'HEAD';
            const isCommitOrRange = diffTo !== null;
            const usesNewSideRef = d.target === 'file' || d.side !== 'old';
            const newSideBroken =
                (d.status === 'added'   && isWorkingTreeOnly) ||
                (d.status === 'deleted' && isCommitOrRange);
            const disabled = usesNewSideRef && newSideBroken;
            const disabledReason = disabled
                ? (d.status === 'added' ? 'File not pushed to remote yet' : 'File was removed at this commit')
                : '';
            const menuW = 220;
            const menuH = disabled ? 110 : 80;
            this.remoteMenu = {
                open: true,
                x: Math.min(d.clientX, window.innerWidth - menuW - margin),
                y: Math.min(d.clientY, window.innerHeight - menuH - margin),
                projectSlug: @js($projectSlug),
                type, params, label, disabled, disabledReason,
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
        pathDir(path) {
            if (!path) return '';
            const i = path.lastIndexOf('/');
            return i === -1 ? '' : path.slice(0, i + 1);
        },
        pathBase(path) {
            if (!path) return '';
            const i = path.lastIndexOf('/');
            return i === -1 ? path : path.slice(i + 1);
        },
        repoPath: @js($repoPath),
        get visibleFileCount() {
            return this.sourceFileEntries.filter(f => this.fileMatchesFilter(f.path, f.id)).length;
        },
        buildFullPath(path) {
            const repo = this.repoPath || '';
            if (!repo) return path;
            return repo.replace(/\/+$/, '') + '/' + path;
        },
        scrollToFile(id) {
            this.activeFile = id;
            this.$dispatch('expand-file', { id });
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        scrollToComment(commentId, filePath) {
            const file = this.sourceFileEntries.find(f => f.path === filePath);
            if (!file) {
                Flux.toast({ text: 'Comment is on a file not in this diff', variant: 'warning' });
                return;
            }
            if (!this.fileMatchesFilter(file.path, file.id)) {
                this.fileFilter = '';
                this.hideReviewed = false;
            }
            (window.__rfaPendingExpandFiles ??= new Set()).add(file.id);
            this.scrollToFile(file.id);
            clearTimeout(this.commentScrollPollId);
            const target = 'comment-' + commentId;
            const start = performance.now();
            const tryScroll = () => {
                if (!this.$el?.isConnected) return;
                {{-- Re-dispatch every tick: the diff-file may be lazy and hydrate after the first dispatch, --}}
                {{-- in which case its listeners weren't yet registered to receive the initial expand-file. --}}
                this.$dispatch('expand-file', { id: file.id });
                this.$dispatch('unfold-for-comment', { fileId: file.id });
                const el = document.getElementById(target);
                if (el && el.offsetParent !== null) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                if (performance.now() - start < 4000) {
                    this.commentScrollPollId = setTimeout(tryScroll, 100);
                }
            };
            tryScroll();
        },
        destroy() {
            clearTimeout(this.commentScrollPollId);
        }
    }"
    @scroll-to-comment.window="scrollToComment($event.detail.commentId, $event.detail.filePath)"
    @file-reviewed-changed.window="reviewedFiles[$event.detail.id] = $event.detail.reviewed"
    @reset-reviewed-files.window="reviewedFiles = {}"
    @reviewed-files-reverted.window="($event.detail.fileIds || []).forEach(id => { reviewedFiles[id] = false })"
    @open-remote-menu.window="showRemoteMenu($event)"
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
                <div x-show="remoteMenu.disabled" x-cloak class="px-3 py-1.5 text-xs font-mono text-gh-muted italic" x-text="remoteMenu.disabledReason"></div>
                @native
                    <button
                        type="button"
                        :disabled="remoteMenu.disabled"
                        @click.stop="$wire.openRemote(remoteMenu.projectSlug, remoteMenu.type, remoteMenu.params); closeRemoteMenu()"
                        class="w-full text-left px-3 py-1.5 text-xs font-mono text-gh-text hover:bg-gh-border/40 flex items-center gap-2 cursor-pointer disabled:text-gh-muted/60 disabled:cursor-not-allowed disabled:hover:bg-transparent"
                    >
                        <flux:icon icon="arrow-top-right-on-square" variant="outline" class="!size-3.5 text-gh-muted" />
                        <span>Open </span><span x-text="remoteMenu.label"></span>
                    </button>
                @endnative
                <button
                    type="button"
                    :disabled="remoteMenu.disabled"
                    @click.stop="$wire.copyRemoteLink(remoteMenu.projectSlug, remoteMenu.type, remoteMenu.params); closeRemoteMenu()"
                    class="w-full text-left px-3 py-1.5 text-xs font-mono text-gh-text hover:bg-gh-border/40 flex items-center gap-2 cursor-pointer disabled:text-gh-muted/60 disabled:cursor-not-allowed disabled:hover:bg-transparent"
                >
                    <flux:icon icon="link" variant="outline" class="!size-3.5 text-gh-muted" />
                    <span>Copy </span><span x-text="remoteMenu.label"></span><span> link</span>
                </button>
            </div>
        </template>
    @endif

    <x-page-header>
        @native
            <x-slot:above>
                <livewire:update-banner />
            </x-slot:above>
        @endnative
            <div class="flex items-center gap-2">
                <div
                    @if($hasRemote)
                        @contextmenu.prevent="$dispatch('open-remote-menu', {
                            target: 'direct',
                            type: 'repo',
                            params: {},
                            label: 'repository',
                            projectSlug: @js($projectSlug),
                            clientX: $event.clientX,
                            clientY: $event.clientY,
                        })"
                    @endif
                    class="inline-flex"
                >
                    @native
                        <livewire:project-picker :current-slug="$projectSlug" :project-name="$projectName" mode="review" />
                    @else
                        <x-page-title>{{ $projectName }}</x-page-title>
                    @endnative
                </div>
                <x-mode-toggle
                    mode="review"
                    :project-slug="$projectSlug"
                    :has-review-activity="false"
                    :has-context-activity="$this->hasContextActivity"
                />
                @php
                    $shortFrom = $diffFrom === 'HEAD' ? 'HEAD' : substr($diffFrom, 0, 7);
                    $shortTo = $diffTo ? substr($diffTo, 0, 7) : null;
                    [$selectionLabel, $selectionTitle] = match (true) {
                        $diffTo === null && $diffFrom === 'HEAD'
                            => ['Working tree', 'Working tree changes'],
                        $isSinceBaseView
                            => ['Since '.$defaultBaseBranch, 'All changes since '.$defaultBaseBranch.' (commits + uncommitted)'],
                        $diffTo === null
                            => ['WT · '.$shortFrom, 'Working tree + commits through '.$diffFrom],
                        $diffFrom === $diffTo.'^'
                            => [$shortTo, $commitInfo['message'] ?? $diffTo],
                        default
                            => [$shortFrom.'..'.$shortTo, 'Range '.$diffFrom.'..'.$diffTo],
                    };
                @endphp
                @if($projectBranch)
                    <x-header-separator />
                    <livewire:branch-explorer
                        :key="'branch-explorer-'.$projectId.'-'.md5($projectBranch.'|'.$defaultBaseBranch)"
                        :repo-path="$repoPath"
                        :current-branch="$projectBranch"
                        :project-slug="$projectSlug"
                        :active-commit-hash="$diffTo"
                        :active-diff-from="$diffFrom"
                        :has-remote="$hasRemote"
                        :selection-label="$selectionLabel"
                        :selection-title="$selectionTitle"
                        :default-base-branch="$defaultBaseBranch"
                    />
                @endif
                <livewire:comments-drawer :repo-path="$repoPath" :project-id="$projectId ?: null" />
            </div>
            <div class="flex items-center gap-2 text-xs">
                {{-- Hide reviewed toggle --}}
                <div x-show="reviewedCount > 0" x-cloak class="grid place-items-center">
                    <flux:button variant="ghost" size="sm" icon="eye-slash" icon:variant="outline"
                        tooltip="Hide reviewed"
                        aria-label="Hide reviewed"
                        class="col-start-1 row-start-1"
                        @click="hideReviewed = true"
                        x-show="!hideReviewed" />
                    <flux:button variant="ghost" size="sm" icon="eye" icon:variant="outline"
                        tooltip="Show all files"
                        aria-label="Show all files"
                        class="col-start-1 row-start-1"
                        @click="hideReviewed = false; $wire.clearRecentlyReviewed()"
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

                {{-- Unified/Split diff view toggle --}}
                <div class="grid place-items-center">
                    <flux:button variant="ghost" size="sm" icon="view-columns" icon:variant="outline"
                        tooltip="Switch to split view"
                        aria-label="Switch to split view"
                        class="col-start-1 row-start-1"
                        @click="$store.settings.diffViewMode = 'split'"
                        x-show="$store.settings.diffViewMode !== 'split'" x-cloak />
                    <flux:button variant="ghost" size="sm" icon="bars-3" icon:variant="outline"
                        tooltip="Switch to unified view"
                        aria-label="Switch to unified view"
                        class="col-start-1 row-start-1"
                        @click="$store.settings.diffViewMode = 'unified'"
                        x-show="$store.settings.diffViewMode === 'split'" x-cloak />
                </div>

                <span class="w-px h-4 bg-gh-border" aria-hidden="true"></span>

                @if(! $this->isCommitMode())
                    <div data-testid="change-polling" x-data="{
                        hasChanges: false,
                        fingerprint: null,
                        currentCount: 0,
                        stopPoll: null,
                        async check() {
                            try {
                                const res = await fetch('/api/changes/{{ $projectId }}');
                                const data = await res.json();
                                if (this.fingerprint === null) {
                                    this.fingerprint = data.fingerprint;
                                } else if (data.fingerprint !== this.fingerprint) {
                                    const newCount = data.count ?? 0;
                                    if (! this.hasChanges || this.currentCount !== newCount) {
                                        this.hasChanges = true;
                                        this.currentCount = newCount;
                                    }
                                }
                            } catch {}
                        },
                        softRefresh() { $wire.softRefresh(); },
                        hardReload() { window.location.reload(); },
                        get tooltip() {
                            if (!this.hasChanges) return 'Refresh · ⌘R · ⌘⇧R to hard reload';
                            const n = this.currentCount;
                            const noun = n === 1 ? 'file' : 'files';
                            return `${n} ${noun} changed externally — click to refresh`;
                        },
                        init() {
                            this.check();
                            this.stopPoll = window.smartPoll.startSmartPoll({
                                window,
                                document,
                                getInterval: () => window.smartPoll.isFocused(document) ? 60000 : (document.hidden ? null : 300000),
                                onTick: () => this.check(),
                            });
                            $store.keymap.register('⌘R', () => this.softRefresh(), { allowInEditable: true });
                            $store.keymap.register('⌘⇧R', () => this.hardReload(), { allowInEditable: true });
                        },
                        destroy() {
                            if (this.stopPoll) this.stopPoll();
                        },
                    }"
                    @fingerprint-reset.window="fingerprint = null; hasChanges = false; currentCount = 0; check();"
                    @refresh-completed.window="
                        const n = $event.detail?.changedCount ?? 0;
                        Flux.toast({
                            text: n === 0 ? 'Up to date' : (n === 1 ? '1 file updated' : `${n} files updated`),
                            variant: n === 0 ? 'info' : 'success',
                        });
                    "
                    class="relative flex items-center">
                        <flux:tooltip>
                            <flux:button variant="ghost" size="sm" icon="arrow-path" icon:variant="outline"
                                x-bind:aria-label="tooltip"
                                x-bind:class="hasChanges && '!text-gh-attention'"
                                wire:click.preserve-scroll="softRefresh" />
                            <flux:tooltip.content>
                                <span x-text="tooltip"></span>
                            </flux:tooltip.content>
                        </flux:tooltip>
                        <x-pulse-dot
                            size="md"
                            x-show="hasChanges"
                            x-cloak
                            class="absolute -top-0.5 -right-0.5"
                            label="external changes pending"
                        />
                    </div>
                @endif

                {{-- Settings --}}
                @if(! $this->isCommitMode())
                    <flux:dropdown position="bottom" align="end">
                        <flux:tooltip content="Settings">
                            <flux:button variant="ghost" size="sm" icon="cog-6-tooth" icon:variant="outline"
                                aria-label="Settings" />
                        </flux:tooltip>
                        <flux:menu>
                            <flux:menu.item keep-open>
                                <flux:checkbox wire:model.live="respectGlobalGitignore" label="Global .gitignore" class="text-xs whitespace-nowrap" />
                            </flux:menu.item>
                            <p class="px-3 pb-2 text-[10px] font-mono text-gh-muted/80 leading-snug w-56">
                                Hide files matched by your global .gitignore (e.g. ~/.gitignore_global).
                            </p>
                            <flux:menu.separator />
                            <div class="px-3 py-2 w-56 space-y-1.5" wire:ignore.self>
                                <label for="default-base-branch-input" class="block text-[10px] font-display font-semibold uppercase tracking-brutal text-gh-muted">
                                    Base branch
                                </label>
                                <flux:input
                                    id="default-base-branch-input"
                                    data-testid="default-base-branch-input"
                                    wire:model.live.debounce.400ms="defaultBaseBranch"
                                    placeholder="dev, master, main..."
                                    size="sm"
                                    variant="filled"
                                    class="!font-mono text-xs"
                                />
                                <p class="text-[10px] font-mono text-gh-muted/80 leading-snug">
                                    Default branch to compare against. Pre-fills the
                                    @if($defaultBaseBranch !== '')
                                        <span class="text-gh-text">Since {{ $defaultBaseBranch }}</span>
                                        shortcut
                                    @else
                                        Since shortcut (e.g. <span class="text-gh-text">Since dev</span>)
                                    @endif
                                    in the branch picker.
                                </p>
                            </div>
                            <flux:menu.separator />
                            <div class="px-3 py-2 w-72 space-y-2" wire:ignore.self>
                                <label class="block text-[10px] font-display font-semibold uppercase tracking-brutal text-gh-muted">
                                    Linked external paths
                                </label>
                                <p class="text-[10px] font-mono text-gh-muted/80 leading-snug">
                                    Folders outside the repo that show up as commentable files (e.g. design notes from external tools).
                                </p>
                                @if(count($externalPaths) > 0)
                                    <ul class="space-y-1" data-testid="external-paths-list">
                                        @foreach($externalPaths as $index => $row)
                                            <li class="flex items-center gap-2 group" wire:key="external-path-{{ $index }}">
                                                <div class="min-w-0 flex-1">
                                                    <div class="text-xs font-display text-gh-text truncate" title="{{ $row['path'] }}">{{ $row['label'] }}</div>
                                                    <x-file-path :path="$row['path']" class="text-[10px] text-gh-muted/70" />
                                                </div>
                                                <flux:tooltip content="Unlink">
                                                    <flux:button
                                                        size="xs"
                                                        variant="ghost"
                                                        icon="x-mark"
                                                        icon:variant="outline"
                                                        wire:click="removeExternalPath({{ $index }})"
                                                        aria-label="Unlink {{ $row['label'] }}"
                                                        data-testid="external-path-remove-{{ $index }}"
                                                    />
                                                </flux:tooltip>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="plus"
                                    icon:variant="outline"
                                    wire:click="addExternalPath"
                                    wire:loading.attr="disabled"
                                    wire:target="addExternalPath"
                                    data-testid="external-path-add"
                                    class="w-full"
                                >
                                    <span wire:loading.remove wire:target="addExternalPath">Link folder…</span>
                                    <span wire:loading wire:target="addExternalPath">Opening…</span>
                                </flux:button>
                            </div>
                        </flux:menu>
                    </flux:dropdown>
                @endif

                <livewire:theme-switcher />
            </div>

        <x-slot:below>
            <x-status-strip :source-files="$sourceFiles" :review-pairs="$reviewPairs" />
        </x-slot:below>
    </x-page-header>

    {{-- Branch divergence banner + polling island (working-tree mode only) --}}
    @if(! $this->isCommitMode())
        <livewire:head-divergence-poller
            wire:key="head-divergence-poller-{{ $projectId }}-{{ $diffFrom }}-{{ $projectBranch }}"
            :repo-path="$repoPath"
            :target="$projectBranch"
        />

        @if($divergenceState === DivergenceState::Diverged)
            <div class="px-5 py-3 border-b border-gh-border" role="status" aria-live="polite" data-testid="divergence-banner-diverged">
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
            <div class="px-5 py-3 border-b border-gh-border" role="status" aria-live="polite" data-testid="divergence-banner-detached">
                <flux:callout icon="information-circle" variant="secondary" inline>
                    <flux:callout.heading>Repo detached at <span class="font-mono">{{ $divergenceContext['shortSha'] }}</span></flux:callout.heading>
                    <flux:callout.text>Still reviewing <span class="font-mono">{{ $divergenceContext['target'] }}</span>.</flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" variant="ghost" wire:click="dismissDetachedBanner">Dismiss</flux:button>
                    </x-slot>
                </flux:callout>
            </div>
        @elseif($divergenceState === DivergenceState::MissingTarget)
            <div class="px-5 py-3 border-b border-gh-border" role="alert" aria-live="assertive" data-testid="divergence-banner-missing">
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

    @if($commitInfo)
        <x-commit-context-bar :commit-info="$commitInfo" :project-slug="$projectSlug" />
    @endif

    <x-resizable-sidebar-shell main-class="pb-24">
        <x-slot:sidebar>
            <div class="p-4">
                @if(! $this->isCommitMode() && count($reviewPairs) > 0)
                    <div class="flex items-center justify-between mb-3">
                        <span class="section-label text-gh-muted">Reviews</span>
                        @if(count($reviewPairs) > 1)
                            <x-arm-commit-button
                                icon="trash"
                                tooltip="Delete all reviews"
                                @confirmed="$wire.deleteAllReviewPairs()"
                            />
                        @endif
                    </div>
                    @foreach($reviewPairs as $pair)
                        <div class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-colors text-gh-text">
                            <span class="text-[10px] font-mono font-medium text-purple-500 dark:text-purple-400 shrink-0">R</span>
                            <button @click="scrollToFile('{{ $pair['id'] }}')" class="truncate text-left font-mono" title="{{ $pair['basename'] }}">
                                {{ $pair['displayName'] }}
                            </button>
                            <x-arm-commit-button
                                icon="trash"
                                tooltip="Delete review"
                                @confirmed="$wire.deleteReviewPair('{{ $pair['basename'] }}')"
                                class="opacity-0 group-hover:opacity-100 transition-opacity ml-auto"
                            />
                        </div>
                    @endforeach
                    <div class="border-b border-gh-border my-3"></div>
                @endif

                <div class="flex items-center justify-between mb-3">
                    <span class="section-label text-gh-muted">Files</span>
                    @if(count($sourceFiles) > 0)
                        <x-copy-paths-button testid-prefix="sidebar-copy-paths" />
                    @endif
                </div>
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
                {{-- Recently reviewed: surfaces just-marked files in Hide-reviewed mode so the user can un-mark in place --}}
                <template x-if="hideReviewed && $wire.recentlyReviewedIds.length">
                    <div data-testid="recently-reviewed-group" class="mb-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="section-label text-gh-muted">Recently reviewed</span>
                            <button type="button"
                                @click="$wire.clearRecentlyReviewed()"
                                class="text-[10px] uppercase tracking-wider text-gh-muted hover:text-gh-text transition-colors"
                                title="Clear recently reviewed list"
                                aria-label="Clear recently reviewed list">Clear</button>
                        </div>
                        <template x-for="id in $wire.recentlyReviewedIds" :key="id">
                            <div
                                x-show="filesById[id] && (fileFilter === '' || filesById[id].path.toLowerCase().includes(fileFilter.toLowerCase()))"
                                x-collapse.duration.200ms
                                class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-opacity duration-150 ease-out text-gh-muted/70"
                            >
                                <button type="button" @click="scrollToFile(id)" class="flex items-center gap-2.5 min-w-0 flex-1">
                                    <span class="font-mono font-medium shrink-0" :class="filesById[id]?.badgeClass" x-text="filesById[id]?.badgeLabel"></span>
                                    <span class="font-mono line-through opacity-80 inline-flex items-baseline min-w-0 max-w-full" :title="filesById[id]?.path">
                                        <span class="min-w-0 truncate text-gh-muted/70" x-text="pathDir(filesById[id]?.path)"></span><span class="shrink-0 text-gh-text" x-text="pathBase(filesById[id]?.path)"></span>
                                    </span>
                                </button>
                                <flux:tooltip content="Un-mark as reviewed">
                                    <button type="button"
                                        @click="$wire.dispatch('toggle-reviewed', { filePath: filesById[id]?.path })"
                                        class="shrink-0 size-3.5 flex items-center justify-center text-gh-green hover:text-gh-text transition-colors"
                                        aria-label="Un-mark as reviewed">
                                        <flux:icon icon="check" variant="outline" class="!size-3.5" />
                                    </button>
                                </flux:tooltip>
                            </div>
                        </template>
                        <div class="border-b border-gh-border my-3"></div>
                    </div>
                </template>
                @foreach($sourceFiles as $file)
                    @php
                        [$badgeColor, $badgeLabel] = match($file['status']) {
                            'added' => ['green', 'A'],
                            'deleted' => ['red', 'D'],
                            'renamed' => ['yellow', 'R'],
                            'commented' => ['zinc', 'C'],
                            default => ['yellow', 'M'],
                        };
                        $remoteStatus = ($file['isUntracked'] ?? false) ? 'added' : ($file['status'] ?? 'modified');
                    @endphp
                    <div
                        x-show="fileMatchesFilter(@js($file['path']), '{{ $file['id'] }}')"
                        x-collapse.duration.200ms
                        @if($hasRemote)
                            @contextmenu.prevent="$dispatch('open-remote-menu', {
                                target: 'file',
                                fileId: @js($file['id']),
                                filePath: @js($file['path']),
                                oldPath: @js($file['oldPath'] ?? null),
                                status: @js($remoteStatus),
                                clientX: $event.clientX,
                                clientY: $event.clientY,
                            })"
                        @endif
                        class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-[opacity,colors] duration-150 ease-out"
                        :class="[
                            activeFile === '{{ $file['id'] }}' ? 'bg-gh-link/10 text-gh-link' : 'text-gh-muted',
                            fileMatchesFilter(@js($file['path']), '{{ $file['id'] }}') ? 'opacity-100' : 'opacity-0',
                        ]"
                    >
                        <button @click="scrollToFile('{{ $file['id'] }}')" class="flex items-center gap-2.5 min-w-0 flex-1">
                            <span class="font-mono font-medium shrink-0 {{ match($badgeLabel) { 'A' => 'text-gh-green', 'D' => 'text-gh-red', 'C' => 'text-gh-muted', default => 'text-gh-attention' } }}">{{ $badgeLabel }}</span>
                            @if($file['isSymlink'] ?? false)
                                <flux:icon icon="link" variant="outline" class="!size-3 text-gh-muted shrink-0" aria-hidden="true" />
                            @endif
                            @php
                                // Build the tooltip in PHP: a literal `"` inside a Blade `{{ }}` interpolation
                                // (e.g. `"\nModified "`) confuses the component attribute parser, which closes
                                // the surrounding HTML attribute at that `"` and shreds the rest into bogus
                                // boolean attributes.
                                $fileTitle = $file['path']
                                    .(($file['isSymlink'] ?? false) ? ' -> '.$file['symlinkTarget'] : '')
                                    .(($file['lastModified'] ?? null) ? "\nModified ".$file['lastModified'] : '');
                            @endphp
                            <x-file-path
                                :path="$file['path']"
                                class="text-xs"
                                :title="$fileTitle"
                            />
                        </button>
                        <flux:tooltip>
                            <button type="button"
                                @click.stop="
                                    const next = !reviewedFiles['{{ $file['id'] }}'];
                                    $dispatch('file-reviewed-changed', { id: '{{ $file['id'] }}', reviewed: next });
                                    $wire.dispatch('toggle-reviewed', { filePath: @js($file['path']) });
                                "
                                class="shrink-0 size-3.5 flex items-center justify-center transition-[opacity,colors]"
                                :class="reviewedFiles['{{ $file['id'] }}']
                                    ? 'text-gh-green hover:text-gh-text'
                                    : 'text-gh-muted/40 opacity-0 group-hover:opacity-100 hover:text-gh-text'"
                                x-bind:aria-label="reviewedFiles['{{ $file['id'] }}'] ? 'Un-mark as reviewed' : 'Mark as reviewed'"
                            >
                                <flux:icon icon="check" variant="outline" class="!size-3.5" />
                            </button>
                            <flux:tooltip.content>
                                <span x-text="reviewedFiles['{{ $file['id'] }}'] ? 'Un-mark as reviewed' : 'Mark as reviewed'"></span>
                            </flux:tooltip.content>
                        </flux:tooltip>
                        <span class="shrink-0 size-3.5 flex items-center justify-center">
                            @if(! $this->isCommitMode() && $file['status'] !== 'commented')
                                <flux:tooltip content="Discard changes">
                                    <button
                                        class="opacity-0 group-hover:opacity-100 transition-opacity text-gh-muted hover:text-gh-text data-loading:pointer-events-none data-loading:opacity-50"
                                        aria-label="Discard changes"
                                        wire:click.stop="discardFileChanges('{{ $file['id'] }}')"
                                    >
                                        <flux:icon icon="arrow-uturn-left" variant="outline" class="!size-3.5" />
                                    </button>
                                </flux:tooltip>
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
                                    aria-label="Restore discarded file"
                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-gh-green hover:text-green-400 shrink-0">
                                    <flux:icon icon="arrow-uturn-left" variant="outline" class="!size-3.5" />
                                </button>
                                <x-arm-commit-button
                                    icon="trash"
                                    tooltip="Permanently delete"
                                    @confirmed="$wire.permanentlyDeleteTrashed({{ $trashed['id'] }})"
                                    class="opacity-0 group-hover:opacity-100 transition-opacity shrink-0"
                                />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-slot:sidebar>

            @if($gitError)
                <div class="flex items-center justify-center h-[60vh]" role="alert" aria-live="assertive">
                    <div class="text-center max-w-lg">
                        <p class="rfa-logo text-3xl text-red-400/30 mb-4" aria-hidden="true">!</p>
                        <h2 class="font-semibold tracking-brutal text-lg mb-2">Git error</h2>
                        <p class="font-mono text-xs text-gh-muted leading-relaxed">{{ $gitError }}</p>
                    </div>
                </div>
            @elseif(empty($files))
                <div class="flex items-center justify-center h-[60vh]">
                    <div class="text-center">
                        <p class="rfa-logo text-5xl text-gh-muted/20 mb-6" aria-hidden="true">rfa</p>
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
                                <button @click="collapsed = !collapsed"
                                    :aria-label="collapsed ? 'Expand review' : 'Collapse review'"
                                    :aria-expanded="!collapsed"
                                    class="text-gh-muted hover:text-gh-text transition-colors">
                                    <flux:icon icon="chevron-down" variant="outline" x-show="!collapsed" />
                                    <flux:icon icon="chevron-right" variant="outline" x-show="collapsed" x-cloak />
                                </button>
                                <span class="text-[10px] font-mono font-medium text-purple-500 dark:text-purple-400 shrink-0">R</span>
                                <span class="font-mono text-sm truncate" title="{{ $pair['displayName'] }}">{{ $pair['displayName'] }}</span>
                                <span class="text-[10px] font-mono text-gh-muted">.md</span>
                                <span class="ml-auto">
                                    <x-arm-commit-button
                                        icon="trash"
                                        tooltip="Delete review"
                                        @confirmed="$wire.deleteReviewPair('{{ $pair['basename'] }}')"
                                    />
                                </span>
                            </div>
                            <div x-show="!collapsed" x-collapse.duration.150ms>
                                <livewire:diff-file
                                    lazy
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
                            </div>
                        </div>
                    @endforeach
                @endif

                {{-- Source Files --}}
                @php $singleFile = count($sourceFiles) === 1 && count($reviewPairs) === 0; @endphp
                @foreach($sourceFiles as $file)
                    <div id="{{ $file['id'] }}"
                         class="border-b border-gh-border transition-opacity duration-150 ease-out"
                         x-show="fileMatchesFilter(@js($file['path']), '{{ $file['id'] }}')"
                         x-collapse.duration.200ms
                         :class="fileMatchesFilter(@js($file['path']), '{{ $file['id'] }}') ? 'opacity-100' : 'opacity-0'">
                        <livewire:diff-file
                            lazy
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
    </x-resizable-sidebar-shell>

    {{-- Undo toast --}}
    @include('livewire.undo-toast')

    <x-feedback-submit-bar
        :submitted="$submitted"
        :export-result="$exportResult"
        copy-again-tooltip="Already on your clipboard — re-copy if you've copied something else since"
    />
</div>

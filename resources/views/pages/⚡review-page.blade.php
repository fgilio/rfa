<?php

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\DeleteReviewFilesAction;
use App\Actions\DeriveReviewStateAction;
use App\Actions\GetFileListAction;
use App\Actions\GroupReviewFilesAction;
use App\Actions\IsSinceBaseViewAction;
use App\Actions\LinkExternalPathAction;
use App\Actions\LoadCommitMetadataAction;
use App\Actions\PersistProjectViewAction;
use App\Actions\RecordProjectEntryAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use App\Actions\ResolveCommitAction;
use App\Actions\ResolveProjectAction;
use App\Actions\ResolveRangeAction;
use App\Actions\ResolveRangeToWorkingAction;
use App\Actions\ReviewCommentWorkflowAction;
use App\Actions\ScanReviewFilesAction;
use App\Actions\SessionStateAction;
use App\Actions\ToggleReviewedAction;
use App\Actions\UnlinkExternalPathAction;
use App\Actions\UpdateProjectSettingAction;
use App\Concerns\InteractsWithRemoteLinks;
use App\Concerns\ManagesCommentReplies;
use App\Concerns\ReviewPage\ExportsReview;
use App\Concerns\ReviewPage\ManagesReviewTrash;
use App\Concerns\ReviewPage\ReviewsBranchDivergence;
use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\DTOs\ReviewCommentMutation;
use App\DTOs\ReviewState;
use App\DTOs\SavedView;
use App\Enums\DivergenceState;
use App\Events\HardReloadShortcutPressed;
use App\Events\RefreshShortcutPressed;
use App\Exceptions\GitCommandException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

use function Illuminate\Support\defer;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithRemoteLinks;
    use ManagesCommentReplies;
    use ExportsReview;
    use ManagesReviewTrash;
    use ReviewsBranchDivergence;

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

    /**
     * The last submitted review, or null while the user is still editing.
     * `path` is the exported .rfa/ file, `clipboard` the prompt copied for it.
     * Both the "Review submitted" bar and the basename a delete matches against
     * are derived from this, so they cannot drift apart.
     *
     * @var array{path: string, clipboard: string}|null
     */
    public ?array $submissionReceipt = null;

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

    public string $fileFilter = '';

    public bool $hideReviewed = false;

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

    #[Locked]
    public int $diffRefreshToken = 0;

    /** True when the active rangeToWorking diff equals `default_base_branch..HEAD..working`. */
    #[Locked]
    public bool $isSinceBaseView = false;

    /** True when the active diff is the whole repo: git's empty tree through the working tree. */
    #[Locked]
    public bool $isSinceBeginningView = false;

    /** @var array{shortHash: string, message: string, author: string, prevHash: ?string, nextHash: ?string}|null */
    #[Locked]
    public ?array $commitInfo = null;

    public DivergenceState $divergenceState = DivergenceState::Aligned;

    /** @var array<string, mixed> */
    public array $divergenceContext = [];

    /** HEAD sha at which the user last dismissed a divergence banner (detached only). */
    public ?string $dismissedAtHead = null;

    /** Head branch the user last chose to keep reviewing past (diverged / missing-target).
     *  Suppresses by branch identity, not sha, so committing on that branch doesn't re-nag. */
    public ?string $dismissedAtBranch = null;

    /** Guards `skipRender()` on poll ticks so the initial mount still renders. */
    #[Locked]
    public bool $divergenceChecked = false;

    /*
    |----------------------------------------------------------------------
    | This page stays ONE Livewire component: it renders N diff-file children,
    | and a parent re-render rehydrates them all (the 1+N hazard, see
    | resources/CLAUDE.md), so its sections cannot be nested sub-components.
    | The class is the event and render coordinator. Cohesive clusters with
    | little render coupling live in App\Concerns\ReviewPage traits; pure
    | decision and write logic lives in Actions.
    |
    | Traits:
    |   ReviewsBranchDivergence  HEAD polling + divergence state machine
    |   ManagesReviewTrash       discard / restore / trash list
    |   ExportsReview            submit / snapshot / copy paths
    |
    | Component regions:
    |   1. Initialization & Diff Context   (mount..fileFingerprint)
    |   2. Comment Management              (addComment..restoreComments)
    |   3. Undo coordinator               (undo, unmarkReviewed)
    |   4. Review State                   (toggleReviewed..updatedGlobalComment)
    |   5. Computed, Helpers & Persistence (reviewState..saveSession)
    |
    | Shared deps: $files, $comments, $reviewedFiles, saveSession(),
    | buildDiffTarget(), refreshFileList(). The coordinator keeps every method
    | that calls skipRender/renderIsland/dispatch, so the 1+N render contract
    | reads in one place.
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

        app(RecordProjectEntryAction::class)->handle($this->projectId, $this->projectSlug);

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
            // Range-to-working mode: /p/{slug}/rw/{from}. Commits from $from through the working tree.
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
        $this->isSinceBeginningView = $this->diffTo === null && $this->diffFrom === DiffTarget::EMPTY_TREE_HASH;

        $this->rehydrateForTarget();
        // Initial resolve: a stored target that has since vanished auto-follows
        // the checked-out branch instead of greeting a fresh open (e.g. the
        // `rfa` CLI deep-link) with the missing-target banner.
        $this->refreshDivergenceState(isInitialResolve: true);

        $this->persistCurrentView($hash, $from, $to, $ref, $baseRef, $rangeFromWorking);

        app(RecordRuntimeDiagnosticAction::class)->handle('page.review.mounted', [
            'project_id' => $this->projectId,
            'project_slug' => $this->projectSlug,
            'repo_hash' => hash('xxh128', $this->repoPath),
            'target' => $this->buildDiffTarget()->contextKey(),
            'is_since_base_view' => $this->isSinceBaseView,
            'is_since_beginning_view' => $this->isSinceBeginningView,
            'file_count' => count($this->files),
            'source_file_count' => count($this->sourceFiles),
            'comment_count' => count($this->comments),
        ]);
    }

    /**
     * Persist the (mode, kind, from, to) shape so that re-entering this
     * project via the picker, the home redirect, or a deep-link puts
     * the user back on the same surface.
     *
     * Kind is derived from the arrival shape (which mount branch fired)
     * rather than from `$diffFrom`/`$diffTo`, because resolved SHAs lose
     * the original "single commit vs explicit range" distinction.
     */
    private function persistCurrentView(?string $hash, ?string $from, ?string $to, ?string $ref, ?string $baseRef, ?string $rangeFromWorking): void
    {
        // Built here rather than inside the deferred closure so an unusable
        // tuple surfaces at mount instead of after the response is sent. Each
        // branch's refs are already resolved (mount aborts on an invalid one).
        $view = match (true) {
            $hash !== null => SavedView::commit((string) $this->diffTo),
            $from !== null && $to !== null => SavedView::range($this->diffFrom, (string) $this->diffTo),
            $rangeFromWorking !== null => $this->isSinceBaseView
                ? SavedView::sinceBase()
                : SavedView::rangeToWorking($this->diffFrom),
            $ref !== null && $baseRef !== null => SavedView::range($this->diffFrom, (string) $this->diffTo),
            default => SavedView::workingTree(),
        };

        $projectId = $this->projectId;
        $repoPath = $this->repoPath;

        // Run after the response is sent: the persisted view is only consumed
        // on the next navigation, so making the user wait for the UPSERT here
        // would be needless mount latency.
        defer(static function () use ($projectId, $repoPath, $view) {
            app(PersistProjectViewAction::class)->handle($projectId, $repoPath, $view);
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
        $this->diffRefreshToken++;
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

    /**
     * Mirror a base-branch change made inside the branch picker back onto the
     * page so the settings input and the "Since {base}" header label stay in
     * sync. The picker already persisted it and re-resolved its own snapshot.
     * Skipping the render keeps the picker (keyed on `defaultBaseBranch`) from
     * remounting out from under the open panel.
     */
    #[On('default-base-branch-changed')]
    public function syncDefaultBaseBranch(string $value): void
    {
        $this->defaultBaseBranch = trim($value);
        $this->isSinceBaseView = $this->detectSinceBaseView();
        $this->skipRender();
    }

    public function addExternalPath(): void
    {
        $this->pickAndLinkExternalPath(isFile: false);
    }

    public function addExternalFile(): void
    {
        $this->pickAndLinkExternalPath(isFile: true);
    }

    private function pickAndLinkExternalPath(bool $isFile): void
    {
        $kind = $isFile ? 'file' : 'folder';

        $dialog = app(\Native\Desktop\Dialog::class)
            ->title($isFile ? 'Link External File' : 'Link External Folder');
        $picked = ($isFile ? $dialog->files() : $dialog->folders())->open();

        if (! is_string($picked) || $picked === '') {
            $this->skipRender();

            return;
        }

        $previousCount = count($this->externalPaths);
        $updated = app(LinkExternalPathAction::class)->handle($this->projectId, $picked);
        if ($updated === null) {
            Flux::toast(variant: 'danger', text: "Could not link {$kind}: ".basename($picked));
            $this->skipRender();

            return;
        }

        if (count($updated) === $previousCount) {
            Flux::toast(text: basename($picked).' is already linked');
            $this->skipRender();

            return;
        }

        $this->externalPaths = $updated;
        $this->reloadSessionAfterFileListChange();
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
        $this->reloadSessionAfterFileListChange();

        if ($removed !== null) {
            Flux::toast(text: 'Unlinked '.$removed);
        }
    }

    /**
     * Reload the file list and re-derive session state after a change that
     * only affects which files are listed (external paths, global .gitignore).
     * Lighter than rehydrateForTarget: it leaves the review-pair scan and trash
     * untouched because neither is sensitive to file-list filtering.
     */
    private function reloadSessionAfterFileListChange(): void
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

        $this->reloadSessionAfterFileListChange();
    }

    /**
     * User-initiated refresh (⌘R, click the refresh affordance, head-advance
     * auto-refresh). Always re-renders. We compute `changedCount` for the
     * "Up to date" / "N files updated" toast, but we do NOT use it to gate
     * rendering: the cost of a needless morph on cmd+r is bounded; the cost
     * of a false negative is silently stale UI. The cache-invalidation done
     * by `rehydrateForTarget` only takes effect when children rehydrate,
     * which requires a render.
     */
    public function softRefresh(): void
    {
        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'completed';
        $before = $this->fileFingerprints($this->files);
        $after = $before;
        $changedCount = 0;
        $changedFileIds = [];

        try {
            $this->rehydrateForTarget();
            $this->refreshDivergenceState();

            $after = $this->fileFingerprints($this->files);
            $changedCount = count(array_diff_assoc($after, $before))
                + count(array_diff_key($before, $after));
            $changedFileIds = array_values(array_unique([
                ...array_keys(array_diff_assoc($after, $before)),
                ...array_keys(array_diff_key($before, $after)),
            ]));

            $this->dispatch('fingerprint-reset');
            $this->dispatch('refresh-completed', changedCount: $changedCount);
        } catch (\Throwable $e) {
            $outcome = 'error';
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'refresh_failed');

            throw $e;
        } finally {
            Context::add('rfa.project_id', $this->projectId);
            Context::add('rfa.project_slug', $this->projectSlug);
            Context::add('rfa.target', $this->buildDiffTarget()->contextKey());
            Context::add('rfa.is_since_base_view', $this->isSinceBaseView);
            Context::add('rfa.is_since_beginning_view', $this->isSinceBeginningView);
            Context::add('rfa.file_count_before', count($before));
            Context::add('rfa.diff_refresh_token', $this->diffRefreshToken);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));
            Context::add('rfa.outcome', $outcome);

            if ($outcome === 'completed') {
                Context::add('rfa.file_count_after', count($after));
                Context::add('rfa.changed_count', $changedCount);
                Context::add('rfa.changed_file_ids', array_slice($changedFileIds, 0, 20));
                Context::add('rfa.changed_file_ids_count', count($changedFileIds));
                Context::add('rfa.changed_file_ids_truncated', count($changedFileIds) > 20);
            }

            Log::info('review.refreshed');
            app(RecordRuntimeDiagnosticAction::class)->handle('review.refreshed', [
                'project_id' => $this->projectId,
                'project_slug' => $this->projectSlug,
                'target' => $this->buildDiffTarget()->contextKey(),
                'outcome' => $outcome,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'file_count_before' => count($before),
                'file_count_after' => $outcome === 'completed' ? count($after) : null,
                'changed_count' => $outcome === 'completed' ? $changedCount : null,
            ]);
        }
    }

    #[On('native:App\\Events\\RefreshShortcutPressed')]
    public function handleNativeRefreshShortcut(string $key = RefreshShortcutPressed::KEY): void
    {
        $this->softRefresh();
    }

    #[On('native:App\\Events\\HardReloadShortcutPressed')]
    public function handleNativeHardReloadShortcut(string $key = HardReloadShortcutPressed::KEY): void
    {
        $this->dispatch('hard-reload-requested');
    }

    /**
     * Per-file change signature used to compute `changedCount` for the
     * post-refresh toast ("Up to date" vs "N files updated"). It does NOT
     * gate rendering. See `softRefresh`.
     *
     * Uses raw mtime + byte size (not the human-readable `lastModified` /
     * `fileSize` strings), because those bucket aggressively
     * (`diffForHumans` short-form rounds to whole seconds against an
     * ever-advancing "now"; `Number::fileSize` rounds to a precision-1
     * unit) and `additions/deletions` from numstat are also too coarse on
     * their own. In 1commit+WC and Since-base modes an in-place edit on
     * a line already changed by some pinned commit moves neither count.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, string>
     */
    private function fileFingerprints(array $files): array
    {
        return collect($files)
            ->mapWithKeys(fn (array $file) => [(string) $file['id'] => $this->fileFingerprint($file)])
            ->all();
    }

    /** @param  array<string, mixed>  $file */
    private function fileFingerprint(array $file): string
    {
        return sprintf(
            '%s|%s|%s|%s|%s',
            $file['status'] ?? '',
            $file['additions'] ?? 0,
            $file['deletions'] ?? 0,
            $file['mtime'] ?? '',
            $file['byteSize'] ?? '',
        );
    }

    // endregion: Initialization & Diff Context

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
        $this->applyCommentMutation(
            app(ReviewCommentWorkflowAction::class)->handle(
                $this->repoPath,
                $this->projectId ?: null,
                $this->buildDiffTarget(),
                $this->files,
                $this->comments,
                $fileId,
                $side,
                $startLine,
                $endLine,
                $body,
                $isDraft,
                $lineSnippet,
            )
        );
    }

    #[On('update-comment')]
    public function updateComment(string $commentId, string $body, bool $isDraft = false): void
    {
        $this->applyCommentMutation(
            app(ReviewCommentWorkflowAction::class)->update($this->comments, $commentId, $body, $isDraft)
        );
    }

    #[On('delete-comment')]
    public function deleteComment(string $commentId): void
    {
        $this->applyCommentMutation(
            app(ReviewCommentWorkflowAction::class)->delete(
                $this->repoPath,
                $this->projectId ?: null,
                $this->comments,
                $commentId,
            )
        );
    }

    /**
     * Apply a comment-write result: swap in the new comments, push the change
     * to each affected diff-file child via comment-updated, offer undo, and
     * settle the render. A null mutation means the write was rejected, so the
     * page renders its current state unchanged.
     *
     * The new comment reaches its child through the event, so the parent skips
     * its own render where the mutation allows. That avoids re-hydrating every
     * diff-file child (the TooManyComponentsException hazard) and keeps the 1+N
     * contract. A divergence transition caught by the write's re-check settles
     * through the divergence islands, so the banner stays current even when
     * the mutation skips the parent render.
     */
    private function applyCommentMutation(?ReviewCommentMutation $mutation): void
    {
        if ($mutation === null) {
            return;
        }

        $this->comments = $mutation->comments;

        foreach ($mutation->affectedFileIds as $fileId) {
            $this->dispatchFileComments($fileId);
        }

        if ($mutation->undo !== null) {
            $this->dispatch(
                'undo-available',
                type: $mutation->undo['type'],
                payload: $mutation->undo['payload'],
                message: $mutation->undo['message'],
            );
        }

        if ($mutation->checksDivergence) {
            $this->recheckDivergenceDuringCommentWrite();
        }

        if ($mutation->skipsRender) {
            $this->skipRender();
        }
    }

    public function clearAllComments(): void
    {
        $this->applyCommentMutation(
            app(ReviewCommentWorkflowAction::class)->clearAll(
                $this->repoPath,
                $this->projectId ?: null,
                $this->comments,
            )
        );
    }

    /** @param  array<int, array<string, mixed>>  $comments */
    public function restoreComments(array $comments): void
    {
        $this->applyCommentMutation(
            app(ReviewCommentWorkflowAction::class)->restore($this->repoPath, $this->projectId ?: null, $this->comments, $comments)
        );
    }

    // endregion: Comment Management

    // region: Undo coordinator

    public function undo(string $type, mixed $payload): void
    {
        match ($type) {
            'delete', 'clear-all' => $this->restoreComments($payload),
            'delete-reply' => $this->restoreCommentReply($payload),
            'discard' => $this->restoreDiscardedFile($payload),
            self::UNDO_TYPE_MARK_REVIEWED => $this->unmarkReviewed($payload['filePaths'] ?? []),
            self::UNDO_TYPE_SWITCH_BRANCH => $this->restoreReviewBranch(is_array($payload) ? $payload : []),
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

        $this->settleReviewedRender();
    }

    // endregion: Undo coordinator

    // region: Review State

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
        // (.json/.md) never consume a slot in the "Recently reviewed" group.
        // that group renders from sourceFiles only.
        $fileId = collect($this->sourceFiles)->firstWhere('path', $filePath)['id'] ?? null;

        if (! $wasReviewed && $isNowReviewed) {
            if ($fileId !== null) {
                $this->recentlyReviewedIds = array_slice(
                    array_values(array_unique(array_merge([$fileId], $this->recentlyReviewedIds))),
                    0, 5,
                );

                // Authoritative mark broadcast, symmetric with the un-mark branch's
                // reviewed-files-reverted. DiffFile's `reviewed` flag converges to the
                // server state even when an optimistic dispatch carried a stale value
                // (e.g. a rapid double-click on the sidebar button before the
                // file-list island re-rendered with the fresh toggle direction).
                $this->dispatch('file-reviewed-changed', id: $fileId, reviewed: true);
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

            // Single un-mark transition uses the same broadcast as bulk undo so
            // DiffFile's `reviewed` flag flips in step with the server state.
            // The sidebar and counter refresh through their islands (see
            // settleReviewedRender), so callers don't have to dual-dispatch.
            $this->dispatch('reviewed-files-reverted', fileIds: [$fileId]);
        }

        $this->settleReviewedRender();
    }

    public function clearRecentlyReviewed(): void
    {
        $this->recentlyReviewedIds = [];

        $this->settleRecentlyReviewedRender();
    }

    public function hideReviewedFiles(): void
    {
        $this->hideReviewed = true;

        $this->settleReviewedVisibilityRender();
    }

    public function showAllFiles(): void
    {
        $this->hideReviewed = false;
        $this->recentlyReviewedIds = [];

        $this->settleReviewedVisibilityRender();
    }

    public function clearFileFilter(): void
    {
        $this->fileFilter = '';
        $this->forgetReviewState();
    }

    public function selectFile(string $fileId): void
    {
        $this->activeFileId = $fileId;
        $this->skipRender();
    }

    public function revealFile(string $fileId): void
    {
        $this->activeFileId = $fileId;

        // Method-call form on purpose: the property form would cache this
        // pre-mutation state for the render that follows the resets below.
        if ($this->reviewState()->isFileVisible($fileId)) {
            $this->skipRender();

            return;
        }

        $this->fileFilter = '';
        $this->hideReviewed = false;
        $this->recentlyReviewedIds = [];
        $this->forgetReviewState();
    }

    public function updatedGlobalComment(): void
    {
        $this->saveSession();
        $this->skipRender();
    }

    // endregion: Review State

    // region: Computed, Helpers & Persistence

    #[Computed]
    public function reviewState(): \App\DTOs\ReviewState
    {
        return app(DeriveReviewStateAction::class)->handle(
            files: $this->sourceFiles,
            reviewedFiles: $this->reviewedFiles,
            selectedFileId: $this->activeFileId,
            fileFilter: $this->fileFilter,
            hideReviewed: $this->hideReviewed,
        );
    }

    /** @return list<array{id: string, path: string, badgeLabel: string, badgeClass: string}> */
    #[Computed]
    public function recentlyReviewedFiles(): array
    {
        $filesById = $this->reviewState->filesById;

        return collect($this->recentlyReviewedIds)
            ->map(function (string $id) use ($filesById): ?array {
                $file = $filesById[$id] ?? null;

                if ($file === null) {
                    return null;
                }

                return [
                    'id' => $id,
                    'path' => $file['path'],
                    'badgeLabel' => $file['badgeLabel'],
                    'badgeClass' => $file['badgeClass'],
                ];
            })
            ->filter(fn (?array $file): bool => $file !== null
                && ReviewState::pathMatchesFilter($file['path'], $this->fileFilter))
            ->values()
            ->all();
    }

    /**
     * Settle the response after a reviewed-state change.
     *
     * The affected regions are intentionally islands: summary controls and the
     * sidebar file list always; the visibility islands (source diff list and the
     * visible-file counts) only while hiding reviewed files, since that is the
     * only mode where a toggle drops a file from the visible set.
     */
    private function settleReviewedRender(): void
    {
        $this->forgetReviewState();

        $this->skipRender();
        $this->renderReviewedStateIslands();

        // Only Hide-reviewed mode lets a reviewed toggle change the server-visible
        // list. A path filter is reviewed-independent (ReviewStateService only
        // drops files for reviewed-ness when hideReviewed is on), so refreshing
        // the visibility islands on a filter-only toggle is wasted work on a
        // latency-sensitive path.
        if ($this->hideReviewed) {
            $this->renderVisibilityIslands();
        }
    }

    private function settleReviewedVisibilityRender(): void
    {
        $this->forgetReviewState();

        $this->skipRender();
        $this->renderReviewedStateIslands();
        $this->renderVisibilityIslands();
    }

    private function settleRecentlyReviewedRender(): void
    {
        $this->forgetReviewState();

        $this->skipRender();

        // The Recently-reviewed group lives inside the file-list island and only
        // shows in Hide-reviewed mode, so nothing else needs refreshing here.
        if ($this->hideReviewed) {
            $this->renderIsland('file-list');
        }
    }

    private function renderReviewedStateIslands(): void
    {
        $this->renderIsland('reviewed-toggle');
        $this->renderIsland('reviewed-counter');
        $this->renderIsland('file-list');
    }

    /**
     * Refresh every island that reflects which files are currently visible.
     *
     * Dropping a file in/out of the visible set changes the source diff list and
     * the visible-file counts that live OUTSIDE the sidebar file list: the
     * status-strip count band and the sidebar "Files" header (the j/k hint and
     * copy-paths trigger). Each is its own island so a skipRender() reviewed
     * toggle keeps them in step instead of leaving a stale "N files" behind.
     */
    private function renderVisibilityIslands(): void
    {
        $this->renderIsland('source-diff-list');
        $this->renderIsland('file-count');
        $this->renderIsland('file-list-header');
        $this->renderIsland('status-strip-copy-paths');
    }

    private function forgetReviewState(): void
    {
        unset($this->reviewState);
        unset($this->recentlyReviewedFiles);
    }

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

        // The "Review submitted" bar points at this file — deleting it leaves
        // the bar referencing a file that no longer exists, so drop back to the editor.
        if ($basename === $this->submittedReviewBasename()) {
            $this->resetSubmittedState();
        }

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

        if (in_array($this->submittedReviewBasename(), $basenames, true)) {
            $this->resetSubmittedState();
        }

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
        $this->sourceFiles = $this->withRefreshFingerprints(
            app(GroupReviewFilesAction::class)->handle($this->files)
        );
    }

    private function scanReviewFiles(): void
    {
        $this->reviewPairs = collect(app(ScanReviewFilesAction::class)->handle($this->repoPath))
            ->map(function (array $pair): array {
                if (isset($pair['mdFile']) && is_array($pair['mdFile'])) {
                    $pair['mdFile']['refreshFingerprint'] = $this->fileFingerprint($pair['mdFile']);
                }

                return $pair;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    private function withRefreshFingerprints(array $files): array
    {
        return collect($files)
            ->map(function (array $file): array {
                $file['refreshFingerprint'] = $this->fileFingerprint($file);

                return $file;
            })
            ->all();
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

    // endregion: Computed, Helpers & Persistence
};
?>

@assets
@localScript('js/diff-file.js')
@localScript('js/review-page.js')
@endassets

<div
    data-testid="review-component"
    data-diff-refresh-token="{{ $diffRefreshToken }}"
    data-source-file-entries='@json($this->reviewState->sourceFileEntries)'
    data-visible-file-entries='@json($this->reviewState->visibleFileEntries)'
    @refresh-completed.window="
        const n = $event.detail?.changedCount ?? 0;
        Flux.toast({
            text: n === 0 ? 'Up to date' : (n === 1 ? '1 file updated' : `${n} files updated`),
            variant: n === 0 ? 'info' : 'success',
        });
    "
    x-data="reviewPage({
        activeFile: @js($this->reviewState->selectedFileId),
        projectSlug: @js($projectSlug),
        projectBranch: @js($projectBranch),
        diffFrom: @js($diffFrom),
        diffTo: @js($diffTo),
        repoPath: @js($repoPath),
        prevCommitHash: @js($commitInfo['prevHash'] ?? null),
        nextCommitHash: @js($commitInfo['nextHash'] ?? null),
    })"
    @scroll-to-comment.window="scrollToComment($event.detail.commentId, $event.detail.filePath)"
    @open-remote-menu.window="showRemoteMenu($event)"
    x-on:rfa-toggle-reviewed.window="toggleReviewed($event.detail?.filePath)"
    x-on:rfa-hide-reviewed.window="hideReviewedFiles()"
    x-on:rfa-show-all-files.window="showAllFiles()"
    x-on:rfa-clear-recently-reviewed.window="clearRecentlyReviewed()"
    {{-- Divergence banner buttons sit inside the divergence islands, where a
         wire:click would scope its render to that island. They bridge through
         these window events so the actions run at page scope: switching and
         keep/dismiss all settle more than the banner they were clicked in. --}}
    x-on:rfa-keep-reviewing.window="$wire.keepReviewing()"
    x-on:rfa-switch-review-to-head.window="$wire.switchReviewToHead()"
    x-on:rfa-dismiss-detached-banner.window="$wire.dismissDetachedBanner()"
    x-on:rfa-dismiss-missing-target.window="$wire.dismissMissingTarget()"
    {{-- Filter/file/commit shortcuts are registered through the keymap store
         (see registerShortcuts() in review-page.js). Only the in-input Escape
         stays here, since the store suppresses shortcuts while focus is in an
         input. It blurs whichever non-comment input has focus, but only
         clears the file filter when Escape came from the filter input itself —
         clearing it from other inputs (branch picker, settings, global
         comment) silently threw away the user's filter text. --}}
    @keydown.escape.window="
        if (($event.target.tagName === 'TEXTAREA' || $event.target.tagName === 'INPUT')
            && !$event.target.closest('[data-comment-form]')) {
            if ($event.target.closest('[data-testid=file-filter]')) { $wire.clearFileFilter(); }
            $event.target.blur(); $event.preventDefault();
        }
    "
>
    @if($hasRemote)
        {{-- Shared remote-link context menu (one instance per review-page) --}}
        <template x-teleport="body">
            <div
                x-show="remoteMenu.open"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
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
            <div class="flex items-center gap-2 min-w-0">
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
                        $isSinceBeginningView
                            => ['Since the beginning', 'Entire repository (every commit + uncommitted)'],
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
                        :project-id="$projectId"
                        :current-branch="$projectBranch"
                        :project-slug="$projectSlug"
                        :active-commit-hash="$diffTo"
                        :active-diff-from="$diffFrom"
                        :has-remote="$hasRemote"
                        :selection-label="$selectionLabel"
                        :selection-title="$selectionTitle"
                        :default-base-branch="$defaultBaseBranch"
                    />
                    @if(! $this->isCommitMode())
                        {{-- Island so a banner-only divergence transition repaints the
                             marker without morphing the page (see renderDivergenceIslands). --}}
                        @island(name: 'divergence-marker', always: true)
                            <x-divergence.marker :state="$divergenceState" :context="$divergenceContext" />
                        @endisland
                    @endif
                @endif
                <livewire:comments-drawer :repo-path="$repoPath" :project-id="$projectId ?: null" />
            </div>
            <div class="flex items-center gap-2 text-xs">
                <x-sidebar-toggle-button />

                {{-- Hide reviewed toggle. Separate from the counter island so
                     each reviewed-state fragment has one DOM target. --}}
                @island(name: 'reviewed-toggle', always: true)
                    @if ($this->reviewState->reviewedFileCount > 0)
                        <div class="grid place-items-center">
                            {{-- Bridge through the page root so island children don't
                                 scope reviewed-state actions to their own island. --}}
                            @if($hideReviewed)
                                <flux:button variant="ghost" size="sm" icon="eye" icon:variant="outline"
                                    tooltip="Show all files"
                                    aria-label="Show all files"
                                    class="col-start-1 row-start-1"
                                    @click="$dispatch('rfa-show-all-files')" />
                            @else
                                <flux:button variant="ghost" size="sm" icon="eye-slash" icon:variant="outline"
                                    tooltip="Hide reviewed"
                                    aria-label="Hide reviewed"
                                    class="col-start-1 row-start-1"
                                    @click="$dispatch('rfa-hide-reviewed')" />
                            @endif
                        </div>
                    @endif
                @endisland

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
                    <div data-testid="change-polling"
                        x-data="reviewChangePoller({
                            projectId: {{ $projectId }},
                            keymapEnabled: @js(! config('nativephp-internal.running')),
                            refreshCombo: @js(\App\Support\Shortcuts::display('app.refresh')),
                            hardReloadCombo: @js(\App\Support\Shortcuts::display('app.hard-reload')),
                        })"
                    @fingerprint-reset.window="reset()"
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

                {{-- Project settings --}}
                @if(! $this->isCommitMode())
                    @include('pages.partials.review-settings-dropdown')
                @endif

                <livewire:theme-switcher />
            </div>

        <x-slot:below>
            <x-status-strip :source-files="$sourceFiles" :review-pairs="$reviewPairs" :review-state="$this->reviewState">
                {{-- File count band. Its own island so Hide-reviewed (a skipRender
                     path that re-renders islands, not the strip) keeps "N files" /
                     "X/N files" in step with the visible set instead of leaving a
                     stale total behind. always:true keeps it current on full
                     renders too. Reads only $this state (island scope can't see
                     template locals). --}}
                <x-slot:fileCount>
                    @island(name: 'file-count', always: true)
                        <span>
                            @if ($this->reviewState->visibleFileCount === $this->reviewState->totalFileCount)
                                {{ $this->reviewState->totalFileCount }} {{ Str::plural('file', $this->reviewState->totalFileCount) }}
                            @else
                                {{ $this->reviewState->visibleFileCount }}/{{ $this->reviewState->totalFileCount }} {{ Str::plural('file', $this->reviewState->totalFileCount) }}
                            @endif
                        </span>
                    @endisland
                </x-slot:fileCount>
                {{-- Reviewed-progress counter. Wrapped in a Livewire island so a
                     mark/un-mark re-renders just this counter and meter, not the
                     2200-line review page. Reads only $this state (island scope
                     can't see template locals). --}}
                <x-slot:reviewedSummary>
                    {{-- always:true so a full render (e.g. hide-reviewed mode,
                         filtering) re-renders the counter inline instead of
                         skipping it; renderIsland still scopes the latency path. --}}
                    @island(name: 'reviewed-counter', always: true)
                        @if ($this->reviewState->reviewedFileCount > 0)
                            <div class="flex items-center gap-2">
                                <span data-testid="reviewed-counter">{{ $this->reviewState->reviewedFileCount }}/{{ $this->reviewState->totalFileCount }} reviewed</span>
                                <div class="w-24 h-0.5 bg-gh-border/50 rounded-full overflow-hidden">
                                    <div class="h-full bg-gh-green/70 rounded-full transition-all duration-200" style="width: {{ round($this->reviewState->reviewedFileCount / max(1, $this->reviewState->totalFileCount) * 100) }}%"></div>
                                </div>
                            </div>
                        @endif
                    @endisland
                </x-slot:reviewedSummary>
                <x-slot:copyPaths>
                    @island(name: 'status-strip-copy-paths', always: true)
                        @if($this->reviewState->visibleFileCount > 0)
                            <x-copy-paths-button
                                testid-prefix="status-strip-copy-paths"
                                :visible-count="$this->reviewState->visibleFileCount"
                            />
                        @endif
                    @endisland
                </x-slot:copyPaths>
            </x-status-strip>
        </x-slot:below>
    </x-page-header>

    {{-- Branch divergence: HEAD poller + (missing-target only) blocking bar.
         Diverged / detached surface as a quiet marker on the branch control in
         the header (see <x-divergence.marker> above) so the canvas stays calm. --}}
    @if(! $this->isCommitMode())
        <livewire:head-divergence-poller
            wire:key="head-divergence-poller-{{ $projectId }}-{{ $diffFrom }}-{{ $projectBranch }}"
            :repo-path="$repoPath"
            :target="$projectBranch"
        />

        @island(name: 'divergence-missing-bar', always: true)
            <x-divergence.missing-bar :state="$divergenceState" :context="$divergenceContext" />
        @endisland
    @endif

    @if($commitInfo)
        <x-commit-context-bar :commit-info="$commitInfo" :project-slug="$projectSlug" />
    @endif

    <x-resizable-sidebar-shell>
        <x-slot:sidebar>
            <div class="p-4">
                <div
                    data-testid="sidebar-filter-bar"
                    class="sticky top-0 z-20 -mx-4 -mt-4 bg-gh-bg px-4 pt-4 pb-3"
                >
                    {{-- Header island: the j/k hint and copy-paths trigger both read
                         visibleFileCount, which Hide-reviewed changes. Kept separate
                         from the file-list island below so it can refresh on the
                         skipRender visibility path without re-rendering (and stealing
                         focus from) the filter input that follows it. --}}
                    @island(name: 'file-list-header', always: true)
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="section-label text-gh-muted">Files</span>
                            @if($this->reviewState->visibleFileCount > 1)
                                <x-kbd-hint
                                    :keys="['j', 'k']"
                                    class="text-gh-muted/70"
                                    title="j next file · k previous file"
                                    aria-label="Press j for the next file, k for the previous file"
                                />
                            @endif
                        </div>
                        @if(count($sourceFiles) > 0)
                            <x-copy-paths-button
                                testid-prefix="sidebar-copy-paths"
                                :visible-count="$this->reviewState->visibleFileCount"
                            />
                        @endif
                    </div>
                    @endisland
                    <flux:input
                        wire:model.live.debounce.150ms="fileFilter"
                        placeholder="Filter files..."
                        icon="magnifying-glass"
                        icon:variant="outline"
                        clearable
                        kbd="/"
                        size="sm"
                        variant="filled"
                        data-testid="file-filter"
                        x-ref="fileFilterInput"
                    />
                </div>

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
                            <span class="text-[10px] font-mono font-medium text-gh-link shrink-0">R</span>
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
                {{-- File list as an island so a reviewed mark/un-mark refreshes the
                     sidebar checkmarks and recovery group without a full page render.
                     always:true keeps it current on full renders too (filtering,
                     discard, hide-reviewed mode). --}}
                @island(name: 'file-list', always: true)
                {{-- Recently reviewed: surfaces just-marked files in Hide-reviewed mode so the user can un-mark in place --}}
                @if($hideReviewed && count($this->recentlyReviewedFiles) > 0)
                    <div data-testid="recently-reviewed-group" class="mb-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="section-label text-gh-muted">Recently reviewed</span>
                            <button type="button"
                                @click="$dispatch('rfa-clear-recently-reviewed')"
                                class="text-[10px] uppercase tracking-wider text-gh-muted hover:text-gh-text transition-colors"
                                title="Clear recently reviewed list"
                                aria-label="Clear recently reviewed list">Clear</button>
                        </div>
                        @foreach($this->recentlyReviewedFiles as $recentlyReviewedFile)
                            <div class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-opacity duration-150 ease-out text-gh-muted/70">
                                <button type="button" @click="scrollToFile('{{ $recentlyReviewedFile['id'] }}')" class="flex items-center gap-2.5 min-w-0 flex-1">
                                    <span class="font-mono font-medium shrink-0 {{ $recentlyReviewedFile['badgeClass'] }}">{{ $recentlyReviewedFile['badgeLabel'] }}</span>
                                    <x-file-path
                                        :path="$recentlyReviewedFile['path']"
                                        class="text-xs line-through opacity-80"
                                        :collapse="true"
                                    />
                                </button>
                                <flux:tooltip content="Un-mark as reviewed">
                                    <button type="button"
                                        @click.stop="$dispatch('rfa-toggle-reviewed', { filePath: @js($recentlyReviewedFile['path']) })"
                                        class="shrink-0 size-3.5 flex items-center justify-center text-gh-green hover:text-gh-text transition-colors"
                                        aria-label="Un-mark as reviewed">
                                        <flux:icon icon="check" variant="outline" class="!size-3.5" />
                                    </button>
                                </flux:tooltip>
                            </div>
                        @endforeach
                        <div class="border-b border-gh-border my-3"></div>
                    </div>
                @endif
                @foreach($this->reviewState->visibleFiles as $file)
                    @php
                        // Badge label and color come from ReviewState so this list and the
                        // Recently-reviewed group render the same status treatment.
                        $badge = $this->reviewState->filesById[$file['id']] ?? null;
                        $badgeLabel = $badge['badgeLabel'] ?? 'M';
                        $badgeClass = $badge['badgeClass'] ?? 'text-gh-attention';
                        $remoteStatus = ($file['isUntracked'] ?? false) ? 'added' : ($file['status'] ?? 'modified');
                        $isReviewed = array_key_exists($file['path'], $reviewedFiles);
                    @endphp
                    <div
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
                        class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-[opacity,colors] duration-150 ease-out focus-within:outline focus-within:outline-1 focus-within:-outline-offset-1 focus-within:outline-gh-accent"
                        {{-- Highlight is Alpine-only: activeFile is seeded from the server's
                             selectedFileId at init, and selectFile() skips render, so baking
                             the server value into the else-branch would leave the previous
                             row highlighted after every client-side selection change. --}}
                        :class="[
                            activeFile === '{{ $file['id'] }}' ? 'bg-gh-text/10 text-gh-text' : 'text-gh-muted',
                        ]"
                    >
                        <button @click="scrollToFile('{{ $file['id'] }}')" class="flex items-center gap-2.5 min-w-0 flex-1">
                            <span class="font-mono font-medium shrink-0 {{ $badgeClass }}">{{ $badgeLabel }}</span>
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
                                :collapse="true"
                            />
                        </button>
                        <flux:tooltip>
                            {{-- Reviewed state is server-rendered inside the file-list island.
                                 The click tells DiffFile's checkbox mirror the new state and
                                 asks the parent to toggle, which refreshes affected islands. --}}
                            <button type="button"
                                @click.stop="
                                    $dispatch('file-reviewed-changed', { id: '{{ $file['id'] }}', reviewed: {{ $isReviewed ? 'false' : 'true' }} });
                                    $dispatch('rfa-toggle-reviewed', { filePath: @js($file['path']) });
                                "
                                class="shrink-0 size-3.5 flex items-center justify-center transition-[opacity,colors] {{ $isReviewed ? 'text-gh-green hover:text-gh-text' : 'text-gh-muted/40 opacity-0 group-hover:opacity-100 hover:text-gh-text' }}"
                                aria-label="{{ $isReviewed ? 'Un-mark as reviewed' : 'Mark as reviewed' }}"
                            >
                                <flux:icon icon="check" variant="outline" class="!size-3.5" />
                            </button>
                            <flux:tooltip.content>{{ $isReviewed ? 'Un-mark as reviewed' : 'Mark as reviewed' }}</flux:tooltip.content>
                        </flux:tooltip>
                        <span class="shrink-0 size-3.5 flex items-center justify-center">
                            @if(! $this->isCommitMode() && ! $isSinceBeginningView && $file['status'] !== 'commented' && ! ($file['isExternal'] ?? false))
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
                {{-- No-match feedback so an active filter never leaves a blank void. --}}
                @if($this->reviewState->emptyStateReason === \App\DTOs\ReviewState::EMPTY_FILTER)
                    <div class="px-2.5 py-6 text-center text-xs text-gh-muted font-mono">
                        No files match "<span class="text-gh-text">{{ trim($fileFilter) }}</span>"
                    </div>
                @elseif($this->reviewState->emptyStateReason === \App\DTOs\ReviewState::EMPTY_ALL_REVIEWED)
                    <div class="px-2.5 py-6 text-center text-xs text-gh-muted font-mono">
                        All files reviewed
                    </div>
                @endif
                @endisland
                @include('pages.partials.review-trash-list', ['trashedFiles' => $trashedFiles])
            </div>
        </x-slot:sidebar>

            @if($gitError)
                <x-empty-state glyph="!" glyph-class="text-gh-red/30" role="alert" aria-live="assertive">
                    <x-slot:heading>Git error</x-slot:heading>
                    <p class="font-mono text-xs text-gh-muted leading-relaxed">{{ $gitError }}</p>
                </x-empty-state>
            @elseif(empty($files))
                @if($this->isCommitMode())
                    <x-empty-state>
                        <x-slot:heading>No file changes in this commit</x-slot:heading>
                        <p class="text-sm text-gh-muted">This commit has no diff (empty or merge commit)</p>
                    </x-empty-state>
                @elseif($divergenceState === DivergenceState::Diverged)
                    {{-- Empty *because* the checkout drifted: tell the one true story instead
                         of a generic "clean" message disconnected from the divergence marker. --}}
                    <x-empty-state>
                        <x-slot:heading>Nothing to review on <span class="font-mono">{{ $divergenceContext['target'] ?? $projectBranch }}</span></x-slot:heading>
                        <p class="text-sm text-gh-muted leading-relaxed">
                            Your repo is on <span class="font-mono text-gh-text">{{ $divergenceContext['currentBranch'] ?? '' }}</span> right now.
                            Switch your review to it, or edit files on <span class="font-mono text-gh-text">{{ $divergenceContext['target'] ?? $projectBranch }}</span> to see changes here.
                        </p>
                        <x-slot:actions>
                            <button type="button" wire:click="switchReviewToHead" class="text-xs font-medium font-display rounded-md px-3.5 py-2 bg-gh-accent text-gh-bg hover:opacity-90 transition-opacity">Switch review here</button>
                            <button type="button" wire:click="keepReviewing" class="text-xs font-medium text-gh-muted hover:text-gh-text px-3 py-2 transition-colors">Keep reviewing</button>
                        </x-slot:actions>
                    </x-empty-state>
                @else
                    <x-empty-state>
                        <x-slot:heading>Working tree is clean</x-slot:heading>
                        <p class="text-sm text-gh-muted">Edit files to see them here</p>
                    </x-empty-state>
                @endif
            @else
                {{-- Review Pairs (working directory mode only) --}}
                @if(! $this->isCommitMode())
                    @foreach($reviewPairs as $pair)
                        <div id="{{ $pair['id'] }}" class="border-b border-gh-border" x-data="{ collapsed: true }">
                            <div class="sticky top-[var(--header-h)] z-10 bg-gh-surface/80 backdrop-blur-sm border-b border-gh-border px-5 py-2.5 flex items-center gap-2.5">
                                <button @click="collapsed = !collapsed"
                                    :aria-label="collapsed ? 'Expand review' : 'Collapse review'"
                                    :aria-expanded="!collapsed"
                                    class="shrink-0 text-gh-muted hover:text-gh-text transition-colors">
                                    <flux:icon icon="chevron-down" variant="outline" class="!size-4" x-show="!collapsed" />
                                    <flux:icon icon="chevron-right" variant="outline" class="!size-4" x-show="collapsed" x-cloak />
                                </button>
                                <div class="flex items-center gap-1.5 min-w-0 flex-1">
                                    <span class="text-[10px] font-mono font-medium text-gh-link shrink-0">R</span>
                                    <span class="font-mono text-sm truncate min-w-0" title="{{ $pair['displayName'] }}">{{ $pair['displayName'] }}</span>
                                    <span class="text-[10px] font-mono text-gh-muted shrink-0">.md</span>
                                </div>
                                <span class="shrink-0">
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
                                    :key="$pair['mdFile']['id'].'-'.$pair['mdFile']['refreshFingerprint']"
                                    :file="$pair['mdFile']"
                                    :load-delay="0"
                                    :file-comments="$this->groupedComments[$pair['mdFile']['id']] ?? []"
                                    :is-reviewed="array_key_exists($pair['mdFile']['path'], $reviewedFiles)"
                                    :repo-path="$repoPath"
                                    :project-id="$projectId"
                                    :has-remote="$hasRemote"
                                    :diff-from="$diffFrom"
                                    :diff-to="$diffTo"
                                    :allow-discard="! $isSinceBeginningView"
                                />
                            </div>
                        </div>
                    @endforeach
                @endif

                {{-- Source Files --}}
                {{-- Source diffs are isolated from reviewed-state/sidebar islands
                     so hide-reviewed mode can remove mounted lazy diff-file children
                     without morphing the entire review page. --}}
                @island(name: 'source-diff-list', always: true)
                    {{-- Filter-independent: singleFile is read once at a diff-file child's
                         Alpine init and its :key has no filter component, so a filtered
                         1-of-N view can't re-init an already-mounted child. Key off the
                         total source count so the value is stable across filtering. --}}
                    @php $singleFile = $this->reviewState->totalFileCount === 1 && count($reviewPairs) === 0; @endphp
                    @forelse($this->reviewState->visibleFiles as $file)
                        <div id="{{ $file['id'] }}"
                             wire:key="source-file-shell-{{ $file['id'] }}-{{ $file['refreshFingerprint'] }}"
                             class="border-b border-gh-border transition-opacity duration-150 ease-out">
                            <livewire:diff-file
                                lazy
                                :key="$file['id'].'-'.$file['refreshFingerprint']"
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
                                :allow-discard="! $isSinceBeginningView"
                            />
                        </div>
                    @empty
                        @if(count($reviewPairs) === 0)
                            @if($this->reviewState->emptyStateReason === \App\DTOs\ReviewState::EMPTY_FILTER)
                                <x-empty-state>
                                    <x-slot:heading>No files match</x-slot:heading>
                                    <p class="text-sm text-gh-muted">Clear the filter to show all changed files</p>
                                </x-empty-state>
                            @elseif($this->reviewState->emptyStateReason === \App\DTOs\ReviewState::EMPTY_ALL_REVIEWED)
                                <x-empty-state>
                                    <x-slot:heading>All files reviewed</x-slot:heading>
                                    <p class="text-sm text-gh-muted">Show all files to revisit reviewed changes</p>
                                </x-empty-state>
                            @endif
                        @endif
                    @endforelse
                @endisland
            @endif
    </x-resizable-sidebar-shell>

    {{-- Undo toast --}}
    @include('livewire.undo-toast')

    <x-feedback-submit-bar
        :receipt="$submissionReceipt"
        secondary-label="Export snapshot"
        secondary-action="exportSnapshot"
        secondary-icon="arrow-down-tray"
        copy-again-tooltip="Already on your clipboard - re-copy if you've copied something else since"
    />
</div>

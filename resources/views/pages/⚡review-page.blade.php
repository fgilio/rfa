<?php

use App\Actions\AddCommentAction;
use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\CleanExpiredTrashAction;
use App\Actions\DeleteCommentAction;
use App\Actions\DeleteReviewFilesAction;
use App\Actions\DeleteTrashedFileAction;
use App\Actions\DeriveReviewStateAction;
use App\Actions\DiscardFileChangesAction;
use App\Actions\ExportReviewAction;
use App\Actions\ExportReviewSnapshotAction;
use App\Actions\GetCurrentHeadAction;
use App\Actions\GetFileListAction;
use App\Actions\GroupReviewFilesAction;
use App\Actions\IsSinceBaseViewAction;
use App\Actions\LinkExternalPathAction;
use App\Actions\LoadCommitMetadataAction;
use App\Actions\PersistProjectViewAction;
use App\Actions\RecordRuntimeDiagnosticAction;
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
use App\DTOs\ReviewState;
use App\Enums\DiffSide;
use App\Enums\DivergenceState;
use App\Enums\GitRef;
use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use App\Events\HardReloadShortcutPressed;
use App\Events\RefreshShortcutPressed;
use App\Exceptions\GitCommandException;
use App\Listeners\HandleMenuItemClicked;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
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

    /** Undo-toast `type` for the "marked file as reviewed" action. */
    private const UNDO_TYPE_MARK_REVIEWED = 'mark-reviewed';

    private const UNDO_TYPE_SWITCH_BRANCH = 'switch-branch';

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

        Cache::put(HandleMenuItemClicked::ACTIVE_PROJECT_CACHE_KEY, $this->projectId, now()->addDay());

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

        $this->rehydrateForTarget();
        $this->checkHeadDivergence();

        $this->persistCurrentView($hash, $from, $to, $ref, $baseRef, $rangeFromWorking);

        app(RecordRuntimeDiagnosticAction::class)->handle('page.review.mounted', [
            'project_id' => $this->projectId,
            'project_slug' => $this->projectSlug,
            'repo_hash' => hash('xxh128', $this->repoPath),
            'target' => $this->buildDiffTarget()->contextKey(),
            'is_since_base_view' => $this->isSinceBaseView,
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
            Context::add('rfa.error', $e->getMessage());
            Context::add('rfa.error_class', $e::class);

            throw $e;
        } finally {
            Context::add('rfa.project_id', $this->projectId);
            Context::add('rfa.project_slug', $this->projectSlug);
            Context::add('rfa.target', $this->buildDiffTarget()->contextKey());
            Context::add('rfa.is_since_base_view', $this->isSinceBaseView);
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

    // region: Branch Divergence

    #[On('head-divergence-transitioned')]
    public function checkHeadDivergence(): void
    {
        if (! $this->refreshDivergenceState()) {
            $this->skipRender();
        }
    }

    /**
     * HEAD advanced on the same branch the user is reviewing, typically
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
            if ($this->dismissedAtBranch === $head->branch) {
                $this->markAligned();

                return;
            }

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

        // Suppress by branch identity, not sha: once the user opts to keep reviewing
        // their target, committing on the diverged branch shouldn't re-nag every commit.
        if ($this->dismissedAtBranch === $head->branch) {
            $this->markAligned();

            return;
        }

        $this->divergenceState = DivergenceState::Diverged;
        $this->divergenceContext = [
            'target' => $target,
            'currentBranch' => $head->branch,
            'currentSha' => $head->sha,
            'shortSha' => substr($head->sha, 0, 7),
            'commentCount' => $this->persistedCommentCount(),
        ];
    }

    public function switchReviewToHead(): void
    {
        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->projectBranch ?: null);

        if ($head->detached || $head->branch === null || $head->branch === '') {
            return;
        }

        // Capture before autoFollow clears state, so the switch stays undoable.
        $wasDiverged = $this->divergenceState === DivergenceState::Diverged;
        $fromBranch = $this->projectBranch;

        $this->autoFollowToHead($head->branch);

        // Only offer undo when leaving a real, still-existing target (Diverged).
        // MissingTarget's old branch is gone, so undoing would re-point at nothing.
        if ($wasDiverged && $fromBranch !== '' && $fromBranch !== $head->branch) {
            $this->dispatch(
                'undo-available',
                type: self::UNDO_TYPE_SWITCH_BRANCH,
                payload: ['fromBranch' => $fromBranch],
                message: 'Switched review to '.$head->branch,
            );
        }
    }

    public function keepReviewing(): void
    {
        $branch = $this->divergenceContext['currentBranch'] ?? null;

        if (is_string($branch) && $branch !== '') {
            // Diverged / missing-target: suppress by branch identity.
            $this->dismissedAtBranch = $branch;
        } else {
            // Detached: no branch to key on, so fall back to the sha.
            $sha = $this->divergenceContext['currentSha'] ?? null;

            if (is_string($sha) && $sha !== '') {
                $this->dismissedAtHead = $sha;
            }
        }

        $this->markAligned();
    }

    public function dismissDetachedBanner(): void
    {
        $this->keepReviewing();
    }

    public function dismissMissingTarget(): void
    {
        $this->keepReviewing();
    }

    /** @param array{fromBranch?: string} $payload */
    public function restoreReviewBranch(array $payload): void
    {
        $branch = $payload['fromBranch'] ?? null;

        if (is_string($branch) && $branch !== '') {
            $this->autoFollowToHead($branch);

            // autoFollowToHead() aligns to the restored target, which is right for
            // its "follow HEAD" callers. Here HEAD is still on the branch we
            // switched away from, so the review is diverged again. Recompute now:
            // the head poller won't re-fire (HEAD's identity hasn't changed) and
            // would otherwise leave the divergence marker hidden indefinitely.
            $this->refreshDivergenceState();
        }
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
        $this->dismissedAtBranch = null;
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
        return $this->persistedCommentQuery()->exists();
    }

    private function persistedCommentCount(): int
    {
        return $this->persistedCommentQuery()->count();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\Comment> */
    private function persistedCommentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $projectId = $this->projectId === 0 ? null : $this->projectId;

        return \App\Models\Comment::forProjectOrRepo($projectId, $this->repoPath);
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

        // The new comment reaches its diff-file child via the comment-updated event
        // (dispatchFileComments), so the parent never needs to re-render. Skipping it
        // is the documented contract (CLAUDE.md skipRender table) and avoids a full
        // parent render re-hydrating every diff-file child, the TooManyComponentsException
        // hazard. Divergence transitions are surfaced by the head-divergence poller's
        // own render path, not by piggybacking on comment writes.
        $this->skipRender();
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
        if (! $file || $file['status'] === 'commented' || ($file['isExternal'] ?? false)) {
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

        // Invalidate every diff-cache variant for this file (base + :full-context).
        $projectKey = $this->projectId > 0 ? $this->projectId : $this->repoPath;
        DiffCacheKey::forget($projectKey, $fileId, $this->buildDiffTarget()->contextKey());

        unset($this->reviewedFiles[$file['path']]);

        $this->refreshFileList();
        $this->saveSession();
        $this->loadTrashedFiles();

        $commentCount = count($fileComments);
        $message = $commentCount > 0
            ? 'Discarded '.basename($file['path']).' - '.$commentCount.' comment'.($commentCount === 1 ? '' : 's').' removed'
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
        // (.json/.md) never consume a slot in the "Recently reviewed" group.
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

        if (! $this->hideReviewed) {
            $this->skipRender();
        }
    }

    public function hideReviewedFiles(): void
    {
        $this->hideReviewed = true;
    }

    public function showAllFiles(): void
    {
        $this->hideReviewed = false;
        $this->recentlyReviewedIds = [];
    }

    public function clearFileFilter(): void
    {
        $this->fileFilter = '';
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

        // Never drop a comment silently: if the anchor resolver couldn't place some
        // comments against this diff, they stay in the pool and the user is told.
        $excludedCount = count($result['excludedComments'] ?? []);
        if ($excludedCount > 0) {
            Flux::toast(
                variant: 'warning',
                heading: $excludedCount === 1 ? '1 comment not included' : "{$excludedCount} comments not included",
                text: "Their anchor could not be placed in this diff. They're kept for a later submit.",
            );
        }

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

    public function exportSnapshot(): void
    {
        $this->saveSession();

        $result = app(ExportReviewSnapshotAction::class)->handle(
            repoPath: $this->repoPath,
            files: $this->files,
            comments: $this->comments,
            globalComment: $this->globalComment,
            reviewedFiles: $this->reviewedFiles,
            target: $this->buildDiffTarget(),
            sourceLabel: $this->projectName !== '' ? $this->projectName : basename($this->repoPath),
        );

        Flux::toast(variant: 'success', heading: 'Snapshot exported', text: $result['json']);
        $this->dispatch('copy-to-clipboard', text: $result['clipboard'], toast: 'Snapshot path copied');
    }

    /**
     * Copy the currently visible (filtered) file paths to the clipboard as bare
     * names, repo-relative paths, or absolute paths. The visible set is derived
     * server-side, so a filtered copy always matches what the user sees without
     * the client reconstructing the list from the DOM.
     */
    public function copyVisiblePaths(string $kind = 'relative'): void
    {
        $this->skipRender();

        $paths = collect($this->reviewState->visibleFileEntries)
            ->pluck('path')
            ->filter()
            ->values();

        if ($paths->isEmpty()) {
            return;
        }

        $repoPath = rtrim($this->repoPath, '/');

        $lines = $paths->map(fn (string $path): string => match ($kind) {
            'name' => basename($path),
            'full' => $repoPath === '' ? $path : $repoPath.'/'.$path,
            default => $path,
        });

        $noun = match ($kind) {
            'name' => 'file name',
            'full' => 'full path',
            default => 'relative path',
        };
        $count = $lines->count();
        $toast = $count === 1 ? "Copied {$noun}" : "Copied {$count} {$noun}s";

        $this->dispatch('copy-to-clipboard', text: $lines->implode("\n"), toast: $toast);
    }

    public function startNewReview(): void
    {
        $this->submitted = false;
        $this->exportResult = null;
    }

    // endregion: Review State & Export

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

    private function reviewedChangeNeedsParentRender(): bool
    {
        // Only Hide-reviewed mode lets a reviewed toggle change the server-visible
        // list. A path filter is reviewed-independent (ReviewStateService only
        // drops files for reviewed-ness when hideReviewed is on), so re-rendering
        // the parent — and re-hydrating every mounted diff-file child — on a
        // filter-only toggle is wasted work on a latency-sensitive path.
        return $this->hideReviewed;
    }

    /**
     * Settle the response after a reviewed-state change. Hide-reviewed mode needs
     * a full parent render because the toggle drops the file from the visible
     * list. Every other mode re-renders only the reviewed-summary island, so the
     * counter and meter update without re-rendering the page or re-hydrating the
     * mounted diff-file children.
     */
    private function settleReviewedRender(): void
    {
        // Hide-reviewed mode does a full render because the toggle drops the
        // newly-reviewed file from the visible list. The reviewed-summary island
        // is declared always:true, so it re-renders inline as part of that full
        // render rather than emitting a skip marker.
        if ($this->reviewedChangeNeedsParentRender()) {
            return;
        }

        // Other modes skip the full render for latency and refresh just the
        // islands. Bust the computed cache first so they reflect the reviewedFiles
        // change just made rather than a value memoized earlier.
        unset($this->reviewState);
        $this->skipRender();
        $this->renderIsland('reviewed-summary');
        $this->renderIsland('file-list');
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
    x-data="{
        pendingSaves: 0,
        pendingSavesGuard: null,
        init() {
            this.pendingSavesGuard = window.rfaPendingSaves?.createPendingSavesGuard({
                root: window,
                livewire: Livewire,
                getWireId: () => this.$root.getAttribute('wire:id'),
                onPendingSavesChanged: (count) => { this.pendingSaves = count; },
            });

            this.pendingSavesGuard?.attach();
        },
        activeFile: @js($this->reviewState->selectedFileId),
        remoteMenu: { open: false, x: 0, y: 0, projectSlug: '', type: '', params: {}, label: '', disabled: false, disabledReason: '' },
        jsonData(name, fallback) {
            try {
                return JSON.parse(this.$root?.dataset?.[name] || '');
            } catch (_) {
                return fallback;
            }
        },
        get sourceFileEntries() {
            return this.jsonData('sourceFileEntries', []);
        },
        get visibleFileEntries() {
            return this.jsonData('visibleFileEntries', []);
        },
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
            // status belongs to, so leave it enabled rather than mis-disable.
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
        isFileVisible(fileId) {
            return this.visibleFileEntries.some(entry => entry.id === fileId);
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
            return this.visibleFileEntries.length;
        },
        buildFullPath(path) {
            const repo = this.repoPath || '';
            if (!repo) return path;
            return repo.replace(/\/+$/, '') + '/' + path;
        },
        scrollToFile(id, persist = true) {
            this.activeFile = id;
            // Persist the selection server-side so a later full parent re-render
            // re-seeds the highlight. Skippable when the caller already persisted
            // it (e.g. revealFile) to avoid a redundant round-trip.
            if (persist) {
                $wire.selectFile(id);
            }
            this.$dispatch('expand-file', { id });
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        focusAdjacentFile(delta) {
            // Move the selection between visible files (j/k). Computed client-side
            // off the visible list so rapid presses stay instant; selection clamps
            // at the ends rather than wrapping.
            const entries = this.visibleFileEntries;
            if (entries.length === 0) {
                return;
            }
            const current = entries.findIndex(file => file.id === this.activeFile);
            const target = current === -1
                ? (delta > 0 ? 0 : entries.length - 1)
                : Math.min(entries.length - 1, Math.max(0, current + delta));
            this.scrollToFile(entries[target].id);
        },
        async scrollToComment(commentId, filePath) {
            const file = this.sourceFileEntries.find(f => f.path === filePath);
            if (!file) {
                Flux.toast({ text: 'Comment is on a file not in this diff', variant: 'warning' });
                return;
            }
            const revealed = !this.isFileVisible(file.id);
            if (revealed) {
                await $wire.revealFile(file.id);
            }
            this.activeFile = file.id;
            (window.__rfaPendingExpandFiles ??= new Set()).add(file.id);
            // revealFile already set activeFileId server-side, so don't re-persist.
            this.scrollToFile(file.id, !revealed);
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
            this.pendingSavesGuard?.detach();
            this.pendingSavesGuard = null;
            clearTimeout(this.commentScrollPollId);
        }
    }"
    @scroll-to-comment.window="scrollToComment($event.detail.commentId, $event.detail.filePath)"
    @open-remote-menu.window="showRemoteMenu($event)"
    @keydown.window="
        if ($event.target.tagName === 'TEXTAREA' || $event.target.tagName === 'INPUT') {
            if ($event.key === 'Escape' && !$event.target.closest('[data-comment-form]')) { $wire.clearFileFilter(); $event.target.blur(); $event.preventDefault(); }
            return;
        }
        if ($event.key === '/') { $refs.fileFilterInput?.focus(); $event.preventDefault(); }
        if (($event.key === 'j' || $event.key === 'k') && !$event.metaKey && !$event.ctrlKey && !$event.altKey) { focusAdjacentFile($event.key === 'j' ? 1 : -1); $event.preventDefault(); }
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
        @native
            <x-slot:above>
                <livewire:update-banner />
            </x-slot:above>
        @endnative
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
                    @if(! $this->isCommitMode())
                        <x-divergence.marker :state="$divergenceState" :context="$divergenceContext" />
                    @endif
                @endif
                <livewire:comments-drawer :repo-path="$repoPath" :project-id="$projectId ?: null" />
            </div>
            <div class="flex items-center gap-2 text-xs">
                {{-- Hide reviewed toggle. Same-named island as the counter, so
                     renderIsland('reviewed-summary') flips its visibility in step
                     with the count on a mark/un-mark. always:true keeps it in sync
                     on full renders too. --}}
                @island(name: 'reviewed-summary', always: true)
                    @if ($this->reviewState->reviewedFileCount > 0)
                        <div class="grid place-items-center">
                            @if($hideReviewed)
                                <flux:button variant="ghost" size="sm" icon="eye" icon:variant="outline"
                                    tooltip="Show all files"
                                    aria-label="Show all files"
                                    class="col-start-1 row-start-1"
                                    wire:click="showAllFiles" />
                            @else
                                <flux:button variant="ghost" size="sm" icon="eye-slash" icon:variant="outline"
                                    tooltip="Hide reviewed"
                                    aria-label="Hide reviewed"
                                    class="col-start-1 row-start-1"
                                    wire:click="hideReviewedFiles" />
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
                            return `${n} ${noun} changed externally - click to refresh`;
                        },
                        init() {
                            this.check();
                            this.stopPoll = window.smartPoll.startSmartPoll({
                                window,
                                document,
                                getInterval: () => window.smartPoll.isFocused(document) ? 60000 : (document.hidden ? null : 300000),
                                onTick: () => this.check(),
                            });
                            @browser
                            $store.keymap.register('⌘R', () => this.softRefresh(), { allowInEditable: true });
                            $store.keymap.register('⌘⇧R', () => this.hardReload(), { allowInEditable: true });
                            @endbrowser
                        },
                        destroy() {
                            if (this.stopPoll) this.stopPoll();
                        },
                    }"
                    @fingerprint-reset.window="fingerprint = null; hasChanges = false; currentCount = 0; check();"
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
                                    Folders or single files outside the repo that show up as commentable files (e.g. design notes, a Claude Code plan).
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
                                <div class="flex gap-1">
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="folder-plus"
                                        icon:variant="outline"
                                        wire:click="addExternalPath"
                                        wire:loading.attr="disabled"
                                        wire:target="addExternalPath"
                                        data-testid="external-path-add"
                                        class="flex-1"
                                    >
                                        <span wire:loading.remove wire:target="addExternalPath">Link folder…</span>
                                        <span wire:loading wire:target="addExternalPath">Opening…</span>
                                    </flux:button>
                                    <flux:button
                                        size="xs"
                                        variant="ghost"
                                        icon="document-plus"
                                        icon:variant="outline"
                                        wire:click="addExternalFile"
                                        wire:loading.attr="disabled"
                                        wire:target="addExternalFile"
                                        data-testid="external-file-add"
                                        class="flex-1"
                                    >
                                        <span wire:loading.remove wire:target="addExternalFile">Link file…</span>
                                        <span wire:loading wire:target="addExternalFile">Opening…</span>
                                    </flux:button>
                                </div>
                            </div>
                        </flux:menu>
                    </flux:dropdown>
                @endif

                <livewire:theme-switcher />
            </div>

        <x-slot:below>
            <x-status-strip :source-files="$sourceFiles" :review-pairs="$reviewPairs" :review-state="$this->reviewState">
                {{-- Reviewed-progress summary. Wrapped in a Livewire island so a
                     mark/un-mark re-renders just this counter and meter, not the
                     2200-line review page. Reads only $this state (island scope
                     can't see template locals). --}}
                <x-slot:reviewedSummary>
                    {{-- always:true so a full render (e.g. hide-reviewed mode,
                         filtering) re-renders the counter inline instead of
                         skipping it; renderIsland still scopes the latency path. --}}
                    @island(name: 'reviewed-summary', always: true)
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

        <x-divergence.missing-bar :state="$divergenceState" :context="$divergenceContext" />
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
                <flux:input
                    wire:model.live.debounce.150ms="fileFilter"
                    placeholder="Filter files..."
                    icon="magnifying-glass"
                    icon:variant="outline"
                    clearable
                    kbd="/"
                    size="sm"
                    variant="filled"
                    class="mb-3"
                    x-ref="fileFilterInput"
                    @keydown.escape="$wire.clearFileFilter(); $el.blur()"
                />
                {{-- Recently reviewed: surfaces just-marked files in Hide-reviewed mode so the user can un-mark in place --}}
                @if($hideReviewed && count($this->recentlyReviewedFiles) > 0)
                    <div data-testid="recently-reviewed-group" class="mb-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="section-label text-gh-muted">Recently reviewed</span>
                            <button type="button"
                                wire:click="clearRecentlyReviewed"
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
                                        wire:click="toggleReviewed(@js($recentlyReviewedFile['path']))"
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
                {{-- File list as an island so a reviewed mark/un-mark refreshes the
                     sidebar checkmarks via renderIsland('file-list') without a full
                     page render. always:true keeps it current on full renders too
                     (filtering, discard, hide-reviewed mode). --}}
                @island(name: 'file-list', always: true)
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
                                 asks the parent to toggle, which refreshes this island. --}}
                            <button type="button"
                                @click.stop="
                                    $dispatch('file-reviewed-changed', { id: '{{ $file['id'] }}', reviewed: {{ $isReviewed ? 'false' : 'true' }} });
                                    $wire.dispatch('toggle-reviewed', { filePath: @js($file['path']) });
                                "
                                class="shrink-0 size-3.5 flex items-center justify-center transition-[opacity,colors] {{ $isReviewed ? 'text-gh-green hover:text-gh-text' : 'text-gh-muted/40 opacity-0 group-hover:opacity-100 hover:text-gh-text' }}"
                                aria-label="{{ $isReviewed ? 'Un-mark as reviewed' : 'Mark as reviewed' }}"
                            >
                                <flux:icon icon="check" variant="outline" class="!size-3.5" />
                            </button>
                            <flux:tooltip.content>{{ $isReviewed ? 'Un-mark as reviewed' : 'Mark as reviewed' }}</flux:tooltip.content>
                        </flux:tooltip>
                        <span class="shrink-0 size-3.5 flex items-center justify-center">
                            @if(! $this->isCommitMode() && $file['status'] !== 'commented' && ! ($file['isExternal'] ?? false))
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
                @if(! empty($trashedFiles))
                    <div class="border-t border-gh-border mt-3 pt-3">
                        <span class="section-label text-gh-muted mb-3 block">Trash</span>
                        @foreach($trashedFiles as $trashed)
                            <div class="w-full px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2 group transition-colors"
                                x-data="{
                                    expiresAt: {{ $trashed['expires_at'] ? \Carbon\Carbon::parse($trashed['expires_at'])->getTimestampMs() : 0 }},
                                    remaining: '',
                                    intervalId: null,
                                    init() {
                                        const update = () => {
                                            const ms = this.expiresAt - Date.now();
                                            if (ms <= 0) { this.remaining = 'expired'; clearInterval(this.intervalId); return; }
                                            const m = Math.ceil(ms / 60000);
                                            this.remaining = m < 1 ? '< 1m' : m + 'm';
                                        };
                                        update();
                                        this.intervalId = setInterval(update, 15000);
                                    },
                                    destroy() {
                                        clearInterval(this.intervalId);
                                    },
                                }"
                            >
                                <span class="font-mono text-xs text-gh-muted truncate flex-1" title="{{ $trashed['file_path'] }}">{{ basename($trashed['file_path']) }}</span>
                                <span class="text-[10px] text-gh-muted tabular-nums" x-text="remaining"></span>
                                <button @click="$wire.restoreDiscardedFile({{ $trashed['id'] }})" title="Restore"
                                    aria-label="Restore discarded file"
                                    class="opacity-0 group-hover:opacity-100 transition-opacity text-gh-green hover:text-gh-text shrink-0">
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
                                    class="text-gh-muted hover:text-gh-text transition-colors">
                                    <flux:icon icon="chevron-down" variant="outline" x-show="!collapsed" />
                                    <flux:icon icon="chevron-right" variant="outline" x-show="collapsed" x-cloak />
                                </button>
                                <span class="text-[10px] font-mono font-medium text-gh-link shrink-0">R</span>
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
                                />
                            </div>
                        </div>
                    @endforeach
                @endif

                {{-- Source Files --}}
                {{-- Filter-independent: singleFile is read once at a diff-file child's
                     Alpine init and its :key has no filter component, so a filtered
                     1-of-N view can't re-init an already-mounted child. Key off the
                     total source count so the value is stable across filtering. --}}
                @php $singleFile = $this->reviewState->totalFileCount === 1 && count($reviewPairs) === 0; @endphp
                @forelse($this->reviewState->visibleFiles as $file)
                    <div id="{{ $file['id'] }}"
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
            @endif
    </x-resizable-sidebar-shell>

    {{-- Undo toast --}}
    @include('livewire.undo-toast')

    <x-feedback-submit-bar
        :submitted="$submitted"
        :export-result="$exportResult"
        secondary-label="Export snapshot"
        secondary-action="exportSnapshot"
        secondary-icon="arrow-down-tray"
        copy-again-tooltip="Already on your clipboard - re-copy if you've copied something else since"
    />
</div>

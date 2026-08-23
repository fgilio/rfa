<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\SavedView;
use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use App\Models\Project;
use App\Models\ReviewSession;
use App\Services\GitMetadataService;

/**
 * Single source of truth for "where should the user land when re-entering
 * project {slug}?". Consulted from the project picker, the home redirect,
 * and {@see ResolveStartupRouteAction}.
 *
 * Stale refs (rebased commits, force-pushed branches, removed base branch)
 * silently fall back to the working-tree URL — re-entry never fails closed.
 * `SinceBase` is re-resolved against the current merge-base so the restored
 * view follows base-branch advancement instead of pinning the SHA that was
 * current at save time.
 */
final readonly class ResolveProjectEntryUrlAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
    ) {}

    public function handle(string $slug, ?LastViewMode $fallbackMode = null): string
    {
        $project = Project::where('slug', $slug)->first();

        if ($project === null) {
            return $this->workingTreeUrl($slug);
        }

        $view = $this->savedView($project, $fallbackMode);

        if ($view->mode === LastViewMode::Context) {
            return route('context-page', ['slug' => $slug]);
        }

        return $this->buildReviewUrl($project, $slug, $view)
            ?? $this->workingTreeUrl($slug);
    }

    /**
     * The stored view for this project, or the caller's fallback landing view
     * when nothing was ever saved. Malformed rows degrade to the working tree
     * inside {@see SavedView::fromColumns()}.
     */
    private function savedView(Project $project, ?LastViewMode $fallbackMode): SavedView
    {
        $session = ReviewSession::query()
            ->where(ReviewSession::lookupKey($project->path, $project->id))
            ->first();

        return SavedView::fromColumns(
            $session->last_view_mode ?? $fallbackMode ?? LastViewMode::Review,
            $session?->last_view_kind,
            $session?->last_view_from,
            $session?->last_view_to,
        );
    }

    private function buildReviewUrl(Project $project, string $slug, SavedView $view): ?string
    {
        return match ($view->kind) {
            LastViewKind::SinceBase => $this->buildSinceBaseUrl($project, $slug),
            LastViewKind::Commit => $this->buildCommitUrl($project, $slug, (string) $view->to),
            LastViewKind::Range => $this->buildRangeUrl($project, $slug, (string) $view->from, (string) $view->to),
            LastViewKind::RangeToWorking => $this->buildRangeToWorkingUrl($project, $slug, (string) $view->from),
            // WorkingTree (and the null kind a Context view carries, which
            // handle() returns on before delegating here) has no ref URL to
            // build; the caller falls back to the working-tree route.
            default => null,
        };
    }

    private function buildSinceBaseUrl(Project $project, string $slug): ?string
    {
        $base = trim((string) ($project->default_base_branch ?? ''));

        if ($base === '') {
            return null;
        }

        // On the base branch itself there's nothing to diff "since base".
        if (trim((string) ($project->branch ?? '')) === $base) {
            return null;
        }

        // Inlined merge-base resolution: ResolveBranchBaseAction runs an extra
        // `git log base..HEAD` to list every commit ahead of base, which is
        // proportional to branch length (50–200ms on long-lived branches) and
        // wasted here — the resolver only needs the merge-base for the URL and
        // an "is HEAD ahead?" check (head SHA equality). Two git subprocesses
        // instead of three.
        $mergeBase = $this->gitMetadataService->getMergeBase($project->path, $base, 'HEAD');

        if ($mergeBase === null) {
            return null;
        }

        $headSha = rescue(
            fn (): string => $this->gitMetadataService->getHeadSha($project->path),
            rescue: null,
            report: false,
        );

        if ($headSha === null || $headSha === $mergeBase) {
            return null;
        }

        return route('review-page.range-to-working', [
            'slug' => $slug,
            'rangeFromWorking' => $mergeBase,
        ]);
    }

    private function buildCommitUrl(Project $project, string $slug, string $to): ?string
    {
        if (! $this->refExists($project->path, $to)) {
            return null;
        }

        return route('review-page.commit', ['slug' => $slug, 'hash' => $to]);
    }

    private function buildRangeUrl(Project $project, string $slug, string $from, string $to): ?string
    {
        if (! $this->refExists($project->path, $to)) {
            return null;
        }

        // `from` may carry a parent suffix (e.g. `abc1234^`) when the selection
        // was applied via the catchall route. Strip it for the existence check
        // so a still-reachable commit doesn't fail validation, and round-trip
        // the suffix to the URL via the catchall route shape.
        $fromBase = str_ends_with($from, '^') ? substr($from, 0, -1) : $from;
        if ($fromBase !== '' && ! $this->refExists($project->path, $fromBase)) {
            return null;
        }

        return route('review-page', ['slug' => $slug, 'ref' => $to, 'baseRef' => $from]);
    }

    private function buildRangeToWorkingUrl(Project $project, string $slug, string $from): ?string
    {
        $fromBase = str_ends_with($from, '^') ? substr($from, 0, -1) : $from;

        // "Since the beginning" diffs from git's empty tree, which always exists
        // but is a tree, not a commit, so resolveRef (which forces ^{commit})
        // can't verify it. Rebuild the URL directly rather than failing the gate.
        if ($fromBase === DiffTarget::EMPTY_TREE_HASH) {
            return route('review-page.range-to-working', [
                'slug' => $slug,
                'rangeFromWorking' => DiffTarget::EMPTY_TREE_HASH,
            ]);
        }

        if ($fromBase !== '' && ! $this->refExists($project->path, $fromBase)) {
            return null;
        }

        return route('review-page.range-to-working', [
            'slug' => $slug,
            'rangeFromWorking' => $from,
        ]);
    }

    private function workingTreeUrl(string $slug): string
    {
        return route('review-page', ['slug' => $slug]);
    }

    private function refExists(string $repoPath, string $ref): bool
    {
        return $this->gitMetadataService->resolveRef($repoPath, $ref) !== null;
    }
}

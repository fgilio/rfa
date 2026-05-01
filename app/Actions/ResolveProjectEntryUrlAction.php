<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BranchBaseState;
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
 * `SinceBase` is re-resolved through {@see ResolveBranchBaseAction} so the
 * restored view follows base-branch advancement instead of pinning the SHA
 * that was current at save time.
 */
final readonly class ResolveProjectEntryUrlAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
        private ResolveBranchBaseAction $resolveBranchBase,
    ) {}

    public function handle(string $slug, ?LastViewMode $fallbackMode = null): string
    {
        $project = Project::where('slug', $slug)->first();

        if ($project === null) {
            return $this->workingTreeUrl($slug);
        }

        $session = ReviewSession::query()
            ->where(ReviewSession::lookupKey($project->path, $project->id))
            ->first();

        $mode = ($session === null ? null : $session->last_view_mode)
            ?? $fallbackMode
            ?? LastViewMode::Review;

        if ($mode === LastViewMode::Context) {
            return route('context-page', ['slug' => $slug]);
        }

        if ($session === null) {
            return $this->workingTreeUrl($slug);
        }

        $kind = $session->last_view_kind;

        if ($kind === null || $kind === LastViewKind::WorkingTree) {
            return $this->workingTreeUrl($slug);
        }

        return $this->buildReviewUrl($project, $session, $kind, $slug)
            ?? $this->workingTreeUrl($slug);
    }

    private function buildReviewUrl(Project $project, ReviewSession $session, LastViewKind $kind, string $slug): ?string
    {
        $from = $session->last_view_from;
        $to = $session->last_view_to;

        return match ($kind) {
            LastViewKind::SinceBase => $this->buildSinceBaseUrl($project, $slug),
            LastViewKind::Commit => $this->buildCommitUrl($project, $slug, $to),
            LastViewKind::Range => $this->buildRangeUrl($project, $slug, $from, $to),
            LastViewKind::RangeToWorking => $this->buildRangeToWorkingUrl($project, $slug, $from),
            // Unreachable: `handle()` returns early for WorkingTree before delegating here.
            LastViewKind::WorkingTree => $this->workingTreeUrl($slug),
        };
    }

    private function buildSinceBaseUrl(Project $project, string $slug): ?string
    {
        $result = $this->resolveBranchBase->handle(
            $project->path,
            $project->default_base_branch ?? null,
            $project->branch ?? null,
        );

        if ($result->state !== BranchBaseState::Ready || $result->baseSha === null) {
            return null;
        }

        return route('review-page.range-to-working', [
            'slug' => $slug,
            'rangeFromWorking' => $result->baseSha,
        ]);
    }

    private function buildCommitUrl(Project $project, string $slug, ?string $to): ?string
    {
        if ($to === null || ! $this->refExists($project->path, $to)) {
            return null;
        }

        return route('review-page.commit', ['slug' => $slug, 'hash' => $to]);
    }

    private function buildRangeUrl(Project $project, string $slug, ?string $from, ?string $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }

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

    private function buildRangeToWorkingUrl(Project $project, string $slug, ?string $from): ?string
    {
        if ($from === null) {
            return null;
        }

        $fromBase = str_ends_with($from, '^') ? substr($from, 0, -1) : $from;
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

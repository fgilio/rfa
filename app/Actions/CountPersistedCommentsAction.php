<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;

/**
 * Reads how many comments are persisted for a review target (project, or bare
 * repo path when the review is not project-backed).
 *
 * The branch-divergence flow uses it to decide whether dismissing or switching
 * the review would strand saved comments, so both calls are made lazily, only
 * when a banner decision actually needs them. `handle()` returns the count for
 * the banner message; `exists()` is the cheaper "are there any?" gate that
 * short-circuits on the first row instead of aggregating.
 */
final readonly class CountPersistedCommentsAction
{
    public function handle(string $repoPath, ?int $projectId): int
    {
        return Comment::forProjectOrRepo($projectId, $repoPath)->count();
    }

    public function exists(string $repoPath, ?int $projectId): bool
    {
        return Comment::forProjectOrRepo($projectId, $repoPath)->exists();
    }
}

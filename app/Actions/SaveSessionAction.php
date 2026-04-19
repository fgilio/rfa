<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ReviewSession;

final readonly class SaveSessionAction
{
    /**
     * Persist the repo-scoped `global_comment`.
     *
     * Per-comment and reviewed-file state are now stored row-by-row in their own
     * tables; this action only keeps the free-form repo-level note in sync.
     */
    public function handle(string $repoPath, string $globalComment, ?int $projectId = null): void
    {
        ReviewSession::updateOrCreate(
            $projectId
                ? ['project_id' => $projectId]
                : ['project_id' => null, 'repo_path' => $repoPath],
            [
                'repo_path' => $repoPath,
                'global_comment' => $globalComment,
            ]
        );
    }
}

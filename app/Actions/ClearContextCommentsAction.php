<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;

/**
 * Bulk-delete every context-file comment row in the given set of ids,
 * scoped to origin_ref="context-file" *and* the active project/repo so a
 * stale or cross-workspace id can't drop comments outside the current page.
 */
final readonly class ClearContextCommentsAction
{
    /** @param array<int, string> $commentIds */
    public function handle(string $repoPath, ?int $projectId, array $commentIds): int
    {
        if ($commentIds === []) {
            return 0;
        }

        return Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->where('origin_ref', ContextCommentWorkflowAction::ORIGIN_REF)
            ->whereIn('id', $commentIds)
            ->delete();
    }
}

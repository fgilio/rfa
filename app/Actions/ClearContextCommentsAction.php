<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;

/**
 * Bulk-delete every context-file comment row in the given set of ids,
 * scoped to origin_ref="context-file" so a stray review-comment id can't
 * be deleted through this surface.
 */
final readonly class ClearContextCommentsAction
{
    /** @param array<int, string> $commentIds */
    public function handle(array $commentIds): int
    {
        if ($commentIds === []) {
            return 0;
        }

        return Comment::whereIn('id', $commentIds)
            ->where('origin_ref', ContextCommentWorkflowAction::ORIGIN_REF)
            ->delete();
    }
}

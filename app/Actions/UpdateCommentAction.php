<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;

final readonly class UpdateCommentAction
{
    public function handle(string $commentId, string $body, bool $isDraft = false): bool
    {
        if (! str_starts_with($commentId, 'c-')) {
            return false;
        }

        return Comment::whereKey($commentId)
            ->update(['body' => $body, 'is_draft' => $isDraft]) > 0;
    }
}

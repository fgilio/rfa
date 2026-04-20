<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;

final readonly class DeleteCommentAction
{
    /**
     * @param  array<int, array<string, mixed>>  $comments  Currently loaded view-state comments.
     * @return array<int, array<string, mixed>>|null Updated comments array, or null if invalid id.
     */
    public function handle(array $comments, string $commentId): ?array
    {
        if (! str_starts_with($commentId, 'c-')) {
            return null;
        }

        Comment::whereKey($commentId)->delete();

        return collect($comments)
            ->reject(fn (array $comment): bool => $comment['id'] === $commentId)
            ->values()
            ->all();
    }
}

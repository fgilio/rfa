<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentData;
use App\Models\Comment;

final readonly class LoadCommentThreadAction
{
    /** @return array<string, mixed>|null */
    public function handle(
        string $repoPath,
        ?int $projectId,
        string $commentId,
    ): ?array {
        $comment = Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->with('replies')
            ->find($commentId);

        if ($comment === null) {
            return null;
        }

        return CommentData::fromArray($comment->toArray())->toArray();
    }
}

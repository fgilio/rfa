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
        bool $includeSubmitted = true,
    ): ?array {
        $query = Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->with('replies');

        if (! $includeSubmitted) {
            $query->unsubmitted();
        }

        $comment = $query->find($commentId);

        if ($comment === null) {
            return null;
        }

        return [
            ...CommentData::fromArray($comment->toArray())->toArray(),
            'createdAt' => $comment->created_at?->toIso8601String(),
            'updatedAt' => $comment->updated_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentAuthor;
use App\DTOs\CommentReply as CommentReplyData;
use App\Models\CommentReply;
use Illuminate\Database\Eloquent\Builder;

final readonly class DeleteCommentReplyAction
{
    public function handle(
        string $repoPath,
        ?int $projectId,
        string $replyId,
        CommentAuthor $author,
    ): CommentReplyData {
        $reply = CommentReply::query()
            ->where('author_type', $author->type->value)
            ->where('author_key', $author->key)
            ->whereHas(
                'comment',
                function (Builder $query) use ($projectId, $repoPath): void {
                    $projectId !== null
                        ? $query->where('project_id', $projectId)
                        : $query->whereNull('project_id')->where('repo_path', $repoPath);
                },
            )
            ->findOrFail($replyId);
        $deletedReply = CommentReplyData::fromArray($reply->toArray());

        $reply->delete();

        return $deletedReply;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentAuthor;
use App\DTOs\CommentReply as CommentReplyData;
use App\Models\CommentReply;

final readonly class DeleteCommentReplyAction
{
    public function handle(
        string $repoPath,
        ?int $projectId,
        string $replyId,
        CommentAuthor $author,
    ): CommentReplyData {
        $reply = CommentReply::query()
            ->ownedBy($author->type, $author->key)
            ->forProjectOrRepo($projectId, $repoPath)
            ->findOrFail($replyId);
        $deletedReply = CommentReplyData::fromArray($reply->toArray());

        $reply->delete();

        return $deletedReply;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentAuthor;
use App\DTOs\CommentReply as CommentReplyData;
use App\Models\CommentReply;
use Illuminate\Validation\ValidationException;

final readonly class UpdateCommentReplyAction
{
    public function handle(
        string $repoPath,
        ?int $projectId,
        string $replyId,
        CommentAuthor $author,
        string $body,
    ): CommentReplyData {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'replyBody' => 'The reply body is required.',
            ]);
        }

        $reply = CommentReply::query()
            ->ownedBy($author->type, $author->key)
            ->forProjectOrRepo($projectId, $repoPath)
            ->findOrFail($replyId);

        $reply->update(['body' => $body]);

        return CommentReplyData::fromArray($reply->fresh()->toArray());
    }
}

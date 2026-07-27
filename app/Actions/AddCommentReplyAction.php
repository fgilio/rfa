<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentAuthor;
use App\DTOs\CommentReply as CommentReplyData;
use App\Models\Comment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AddCommentReplyAction
{
    public function handle(
        string $repoPath,
        ?int $projectId,
        string $commentId,
        CommentAuthor $author,
        string $body,
    ): CommentReplyData {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'replyBody' => 'The reply body is required.',
            ]);
        }

        $comment = Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->findOrFail($commentId);

        $reply = $comment->replies()->create([
            'id' => 'r-'.Str::ulid(),
            ...$author->toDatabaseArray(),
            'body' => $body,
        ]);

        return CommentReplyData::fromArray($reply->toArray());
    }
}

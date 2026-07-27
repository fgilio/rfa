<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentAuthor;
use App\DTOs\CommentReply as CommentReplyData;
use App\Models\CommentReply;
use Illuminate\Database\Eloquent\Builder;
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

        $reply = $this->ownedReplyQuery($repoPath, $projectId, $author)
            ->findOrFail($replyId);

        $reply->update(['body' => $body]);

        return CommentReplyData::fromArray($reply->fresh()->toArray());
    }

    /** @return Builder<CommentReply> */
    private function ownedReplyQuery(
        string $repoPath,
        ?int $projectId,
        CommentAuthor $author,
    ): Builder {
        return CommentReply::query()
            ->where('author_type', $author->type->value)
            ->where('author_key', $author->key)
            ->whereHas(
                'comment',
                function (Builder $query) use ($projectId, $repoPath): void {
                    $projectId !== null
                        ? $query->where('project_id', $projectId)
                        : $query->whereNull('project_id')->where('repo_path', $repoPath);
                },
            );
    }
}

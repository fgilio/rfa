<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentReply as CommentReplyData;
use App\Models\Comment;
use App\Models\CommentReply;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

final readonly class RestoreCommentReplyAction
{
    public function handle(
        string $repoPath,
        ?int $projectId,
        CommentReplyData $reply,
    ): CommentReplyData {
        Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->findOrFail($reply->commentId);

        $model = CommentReply::query()->find($reply->id) ?? new CommentReply;

        if ($model->exists && $model->comment_id !== $reply->commentId) {
            throw (new ModelNotFoundException)->setModel(CommentReply::class, [$reply->id]);
        }

        $model->forceFill([
            ...$reply->toDatabaseArray(),
            'created_at' => $reply->createdAt === null ? now() : Carbon::parse($reply->createdAt),
            'updated_at' => $reply->updatedAt === null ? now() : Carbon::parse($reply->updatedAt),
        ]);
        $model->timestamps = false;
        $model->save();

        return CommentReplyData::fromArray($model->fresh()->toArray());
    }
}

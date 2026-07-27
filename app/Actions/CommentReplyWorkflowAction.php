<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentAuthor;
use App\DTOs\CommentReply;
use App\DTOs\CommentReplyMutation;
use App\Models\Comment;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class CommentReplyWorkflowAction
{
    public function __construct(
        private LoadCommentThreadAction $loadCommentThread,
        private AddCommentReplyAction $addCommentReply,
        private UpdateCommentReplyAction $updateCommentReply,
        private DeleteCommentReplyAction $deleteCommentReply,
        private RestoreCommentReplyAction $restoreCommentReply,
    ) {}

    public function handle(
        string $repoPath,
        ?int $projectId,
        string $commentId,
        CommentAuthor $author,
        string $body,
    ): CommentReplyMutation {
        $this->addCommentReply->handle($repoPath, $projectId, $commentId, $author, $body);

        return $this->mutation($repoPath, $projectId, $commentId);
    }

    public function update(
        string $repoPath,
        ?int $projectId,
        string $replyId,
        CommentAuthor $author,
        string $body,
    ): CommentReplyMutation {
        $reply = $this->updateCommentReply->handle($repoPath, $projectId, $replyId, $author, $body);

        return $this->mutation($repoPath, $projectId, $reply->commentId);
    }

    public function delete(
        string $repoPath,
        ?int $projectId,
        string $replyId,
        CommentAuthor $author,
    ): CommentReplyMutation {
        $reply = $this->deleteCommentReply->handle($repoPath, $projectId, $replyId, $author);

        return $this->mutation(
            repoPath: $repoPath,
            projectId: $projectId,
            commentId: $reply->commentId,
            undo: [
                'type' => 'delete-reply',
                'payload' => $reply->toArray(),
                'message' => 'Reply deleted',
            ],
        );
    }

    /** @param  array<string, mixed>  $reply */
    public function restore(
        string $repoPath,
        ?int $projectId,
        array $reply,
    ): CommentReplyMutation {
        $restoredReply = $this->restoreCommentReply->handle(
            $repoPath,
            $projectId,
            CommentReply::fromArray($reply),
        );

        return $this->mutation($repoPath, $projectId, $restoredReply->commentId);
    }

    /**
     * @param  array{type: string, payload: mixed, message: string}|null  $undo
     */
    private function mutation(
        string $repoPath,
        ?int $projectId,
        string $commentId,
        ?array $undo = null,
    ): CommentReplyMutation {
        $comment = $this->loadCommentThread->handle($repoPath, $projectId, $commentId);

        if ($comment === null) {
            throw (new ModelNotFoundException)->setModel(Comment::class, [$commentId]);
        }

        return new CommentReplyMutation(
            commentId: $commentId,
            filePath: (string) $comment['file'],
            replies: $comment['replies'],
            undo: $undo,
        );
    }
}

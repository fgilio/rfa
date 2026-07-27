<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\CommentReplyWorkflowAction;
use App\DTOs\CommentAuthor;
use App\DTOs\CommentReplyMutation;
use Flux\Flux;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\On;

/**
 * Reply mutations shared by comment-owning Livewire pages.
 *
 * The consuming component provides $comments, $repoPath, $projectId,
 * dispatch(), and skipRender().
 */
trait ManagesCommentReplies
{
    #[On('add-comment-reply')]
    public function addCommentReply(string $commentId, string $body): void
    {
        $this->applyCommentReplyMutation(
            app(CommentReplyWorkflowAction::class)->handle(
                $this->repoPath,
                $this->projectId ?: null,
                $commentId,
                CommentAuthor::human(),
                $body,
            ),
        );
    }

    #[On('update-comment-reply')]
    public function updateCommentReply(string $replyId, string $body): void
    {
        $this->applyCommentReplyMutation(
            app(CommentReplyWorkflowAction::class)->update(
                $this->repoPath,
                $this->projectId ?: null,
                $replyId,
                CommentAuthor::human(),
                $body,
            ),
        );
    }

    #[On('delete-comment-reply')]
    public function deleteCommentReply(string $replyId): void
    {
        $this->applyCommentReplyMutation(
            app(CommentReplyWorkflowAction::class)->delete(
                $this->repoPath,
                $this->projectId ?: null,
                $replyId,
                CommentAuthor::human(),
            ),
        );
    }

    /** @param array<string, mixed> $reply */
    public function restoreCommentReply(array $reply): void
    {
        try {
            $mutation = app(CommentReplyWorkflowAction::class)->restore(
                $this->repoPath,
                $this->projectId ?: null,
                $reply,
            );
        } catch (ModelNotFoundException) {
            Flux::toast(
                variant: 'warning',
                text: 'Reply could not be restored because its comment no longer exists.',
            );
            $this->skipRender();

            return;
        }

        $this->applyCommentReplyMutation($mutation);
    }

    private function applyCommentReplyMutation(CommentReplyMutation $mutation): void
    {
        $index = collect($this->comments)->search(
            fn (array $comment): bool => ($comment['id'] ?? null) === $mutation->commentId,
        );
        $fileId = null;

        if ($index !== false) {
            $this->comments[$index]['replies'] = $mutation->replies;
            $fileId = $this->comments[$index]['fileId'] ?? null;
        }

        $this->dispatch(
            'comment-thread-updated',
            commentId: $mutation->commentId,
            fileId: $fileId,
            filePath: $mutation->filePath,
            replies: $mutation->replies,
        );

        if ($mutation->undo !== null) {
            $this->dispatch(
                'undo-available',
                type: $mutation->undo['type'],
                payload: $mutation->undo['payload'],
                message: $mutation->undo['message'],
            );
        }

        $this->skipRender();
    }
}

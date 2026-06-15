<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\ReviewCommentMutation;

/**
 * Comment write workflow for the review page.
 *
 * Wraps the single-purpose AddCommentAction / UpdateCommentAction /
 * DeleteCommentAction and folds their result into a ReviewCommentMutation the
 * page can apply uniformly. Sibling to ContextCommentWorkflowAction, which
 * does the same for context-file comments.
 *
 * `handle()` adds a comment (the primary entry point); `update()` and
 * `delete()` are the secondary operations. Each returns null when the write
 * is rejected so the page renders its current state unchanged.
 */
final readonly class ReviewCommentWorkflowAction
{
    public function __construct(
        private AddCommentAction $addComment,
        private UpdateCommentAction $updateComment,
        private DeleteCommentAction $deleteComment,
    ) {}

    /**
     * Add a draft or submitted comment, returning the mutation to apply or
     * null when the body is empty or the anchor is invalid.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<int, array<string, mixed>>  $comments  Current view-state comments.
     */
    public function handle(
        string $repoPath,
        ?int $projectId,
        DiffTarget $target,
        array $files,
        array $comments,
        string $fileId,
        string $side,
        ?int $startLine,
        ?int $endLine,
        string $body,
        bool $isDraft = false,
        ?string $lineSnippet = null,
    ): ?ReviewCommentMutation {
        $comment = $this->addComment->handle(
            $repoPath,
            $projectId,
            $target,
            $files,
            $fileId,
            $side,
            $startLine,
            $endLine,
            $body,
            $isDraft,
            $lineSnippet,
        );

        if ($comment === null) {
            return null;
        }

        $comments[] = $comment;

        return ReviewCommentMutation::added($comments, $fileId);
    }

    /**
     * Update a comment's body and draft flag, returning the mutation to apply
     * or null when the comment is unknown or the write fails.
     *
     * @param  array<int, array<string, mixed>>  $comments  Current view-state comments.
     */
    public function update(array $comments, string $commentId, string $body, bool $isDraft = false): ?ReviewCommentMutation
    {
        $index = collect($comments)->search(fn (array $comment): bool => $comment['id'] === $commentId);

        if ($index === false) {
            return null;
        }

        if (! $this->updateComment->handle($commentId, $body, $isDraft)) {
            return null;
        }

        $comments[$index]['body'] = $body;
        $comments[$index]['isDraft'] = $isDraft;

        return ReviewCommentMutation::updated($comments, (string) $comments[$index]['fileId']);
    }

    /**
     * Delete a comment, returning the mutation to apply or null when the id is
     * invalid. The mutation carries the deleted row so the page can offer undo.
     *
     * @param  array<int, array<string, mixed>>  $comments  Current view-state comments.
     */
    public function delete(array $comments, string $commentId): ?ReviewCommentMutation
    {
        $deletedComment = collect($comments)->firstWhere('id', $commentId);
        $fileId = $deletedComment['fileId'] ?? null;

        $result = $this->deleteComment->handle($comments, $commentId);

        if ($result === null) {
            return null;
        }

        return ReviewCommentMutation::deleted(
            $result,
            is_string($fileId) ? $fileId : null,
            $deletedComment,
        );
    }
}

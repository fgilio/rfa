<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\ReviewCommentMutation;
use App\Enums\DiffSide;
use App\Enums\GitRef;
use App\Models\Comment;

/**
 * Comment write workflow for the review page.
 *
 * Wraps the single-purpose AddCommentAction / UpdateCommentAction /
 * DeleteCommentAction for single-comment writes, and persists the bulk
 * clear/restore paths directly, folding every result into a
 * ReviewCommentMutation the page applies uniformly. Sibling to
 * ContextCommentWorkflowAction, which does the same for context-file comments.
 *
 * `handle()` adds a comment (the primary entry point); `update()`, `delete()`,
 * `clearAll()`, and `restore()` are the secondary operations. Each returns
 * null when the write is a no-op or rejected, so the page renders its current
 * state unchanged.
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

    /**
     * Clear every loaded comment, returning the mutation to apply or null when
     * there are none. The mutation carries the cleared rows so the page can
     * offer undo.
     *
     * @param  array<int, array<string, mixed>>  $comments  Current view-state comments.
     */
    public function clearAll(array $comments): ?ReviewCommentMutation
    {
        if ($comments === []) {
            return null;
        }

        Comment::whereIn('id', array_column($comments, 'id'))->delete();

        return ReviewCommentMutation::cleared([], $this->affectedFileIds($comments), $comments);
    }

    /**
     * Restore removed comments (the undo path for delete / clear-all),
     * persisting any not already loaded. Returns the mutation to apply or null
     * when every incoming comment is already present.
     *
     * @param  array<int, array<string, mixed>>  $currentComments  Current view-state comments.
     * @param  array<int, array<string, mixed>>  $incomingComments  Comments to restore.
     */
    public function restore(string $repoPath, ?int $projectId, array $currentComments, array $incomingComments): ?ReviewCommentMutation
    {
        $existingIds = collect($currentComments)->pluck('id')->all();

        $newComments = collect($incomingComments)
            ->reject(fn (array $comment): bool => in_array($comment['id'], $existingIds, true))
            ->values()
            ->all();

        if ($newComments === []) {
            return null;
        }

        foreach ($newComments as $comment) {
            Comment::updateOrCreate(
                ['id' => $comment['id']],
                [
                    'project_id' => $projectId,
                    'repo_path' => $repoPath,
                    'origin_ref' => $comment['originRef'] ?? GitRef::Working->value,
                    'file_path' => $comment['file'] ?? '',
                    'side' => $comment['side'] ?? DiffSide::Right->value,
                    'start_line' => $comment['startLine'] ?? null,
                    'end_line' => $comment['endLine'] ?? null,
                    'file_content_hash' => $comment['fileContentHash'] ?? null,
                    'line_snippet' => $comment['lineSnippet'] ?? null,
                    'body' => $comment['body'] ?? '',
                    'is_draft' => (bool) ($comment['isDraft'] ?? false),
                    'submitted_at' => null,
                ],
            );
        }

        $merged = array_merge($currentComments, $newComments);

        return ReviewCommentMutation::restored($merged, $this->affectedFileIds($newComments));
    }

    /**
     * Unique file ids touched by a set of comments, in first-seen order.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @return list<string>
     */
    private function affectedFileIds(array $comments): array
    {
        return array_values(
            collect($comments)
                ->pluck('fileId')
                ->map(fn ($fileId): string => (string) $fileId)
                ->unique()
                ->all()
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentThreadSnapshot;
use App\DTOs\DiffTarget;
use App\DTOs\ReviewCommentMutation;
use App\Enums\CommentSurface;

/**
 * Comment write workflow for the review page.
 *
 * Coordinates single-comment writes, atomic thread deletion, and thread
 * restoration. Every result becomes one ReviewCommentMutation that
 * the page applies uniformly. Sibling to ContextCommentWorkflowAction,
 * which does the same for context-file comments.
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
        private DeleteCommentThreadsAction $deleteCommentThreads,
        private RestoreCommentThreadsAction $restoreCommentThreads,
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
    public function delete(
        string $repoPath,
        ?int $projectId,
        array $comments,
        string $commentId,
    ): ?ReviewCommentMutation {
        $deletedComment = collect($comments)->firstWhere('id', $commentId);
        $fileId = $deletedComment['fileId'] ?? null;
        $result = $this->deleteCommentThreads->handle(
            $repoPath,
            $projectId,
            $comments,
            [$commentId],
            CommentSurface::Review,
        );

        if ($result === null) {
            return null;
        }

        return ReviewCommentMutation::deleted(
            $result->remainingComments,
            is_string($fileId) ? $fileId : null,
            $result->snapshots[0],
        );
    }

    /**
     * Clear every loaded comment, returning the mutation to apply or null when
     * there are none. The mutation carries the cleared rows so the page can
     * offer undo.
     *
     * @param  array<int, array<string, mixed>>  $comments  Current view-state comments.
     */
    public function clearAll(
        string $repoPath,
        ?int $projectId,
        array $comments,
    ): ?ReviewCommentMutation {
        if ($comments === []) {
            return null;
        }

        $result = $this->deleteCommentThreads->handle(
            $repoPath,
            $projectId,
            $comments,
            collect($comments)->pluck('id')->all(),
            CommentSurface::Review,
        );

        if ($result === null) {
            return null;
        }

        return ReviewCommentMutation::cleared(
            $result->remainingComments,
            $this->affectedFileIds($comments),
            $result->snapshots,
        );
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

        $newSnapshots = collect($incomingComments)
            ->reject(fn (array $comment): bool => in_array(
                CommentThreadSnapshot::fromArray($comment)->commentId(),
                $existingIds,
                true,
            ))
            ->values()
            ->all();

        if ($newSnapshots === []) {
            return null;
        }

        $newComments = $this->restoreCommentThreads->handle(
            $repoPath,
            $projectId,
            $newSnapshots,
        );

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

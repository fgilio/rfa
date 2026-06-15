<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Result of a review-page comment write: the new comments view plus the
 * follow-up signals the page must emit.
 *
 * ReviewCommentWorkflowAction produces it; the page applies the uniform tail
 * (refresh affected files, offer undo, re-check divergence, settle the
 * render). Keeping the dispatch and skipRender in the page preserves the 1+N
 * hydration contract: an Action cannot reach Livewire's render pipeline.
 */
final readonly class ReviewCommentMutation
{
    /**
     * @param  array<int, array<string, mixed>>  $comments  New view-state comments.
     * @param  list<string>  $affectedFileIds  Files whose comment list changed.
     * @param  array{type: string, payload: mixed, message: string}|null  $undo
     */
    private function __construct(
        public array $comments,
        public array $affectedFileIds,
        public ?array $undo,
        public bool $checksDivergence,
        public bool $skipsRender,
    ) {}

    /**
     * A comment was added: refresh its file, re-check divergence, and skip the
     * parent render (the new comment reaches its child via comment-updated).
     *
     * @param  array<int, array<string, mixed>>  $comments
     */
    public static function added(array $comments, string $fileId): self
    {
        return new self($comments, [$fileId], null, checksDivergence: true, skipsRender: true);
    }

    /**
     * A comment body or draft flag changed: refresh its file and skip the
     * parent render.
     *
     * @param  array<int, array<string, mixed>>  $comments
     */
    public static function updated(array $comments, string $fileId): self
    {
        return new self($comments, [$fileId], null, checksDivergence: false, skipsRender: true);
    }

    /**
     * A comment was removed: refresh its file when the id is known, offer undo
     * when the deleted row was loaded, and re-check divergence. Renders the
     * parent so the sidebar and empty states reflect the removal.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<string, mixed>|null  $deletedComment
     */
    public static function deleted(array $comments, ?string $fileId, ?array $deletedComment): self
    {
        return new self(
            $comments,
            $fileId !== null && $fileId !== '' ? [$fileId] : [],
            $deletedComment !== null
                ? ['type' => 'delete', 'payload' => [$deletedComment], 'message' => 'Comment deleted']
                : null,
            checksDivergence: true,
            skipsRender: false,
        );
    }

    /**
     * @return array{
     *     comments: array<int, array<string, mixed>>,
     *     affectedFileIds: list<string>,
     *     undo: array{type: string, payload: mixed, message: string}|null,
     *     checksDivergence: bool,
     *     skipsRender: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'comments' => $this->comments,
            'affectedFileIds' => $this->affectedFileIds,
            'undo' => $this->undo,
            'checksDivergence' => $this->checksDivergence,
            'skipsRender' => $this->skipsRender,
        ];
    }
}

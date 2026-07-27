<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CommentReplyMutation
{
    /**
     * @param  list<array<string, mixed>>  $replies
     * @param  array{type: string, payload: mixed, message: string}|null  $undo
     */
    public function __construct(
        public string $commentId,
        public string $filePath,
        public array $replies,
        public ?array $undo = null,
    ) {}

    /**
     * @return array{
     *     commentId: string,
     *     filePath: string,
     *     replies: list<array<string, mixed>>,
     *     undo: array{type: string, payload: mixed, message: string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'commentId' => $this->commentId,
            'filePath' => $this->filePath,
            'replies' => $this->replies,
            'undo' => $this->undo,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CommentThreadSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $comment
     * @param  list<CommentReply>  $replies
     */
    public function __construct(
        public array $comment,
        public array $replies,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $comment = isset($data['comment']) && is_array($data['comment'])
            ? $data['comment']
            : $data;
        $replies = $data['replies'] ?? $comment['replies'] ?? [];

        unset($comment['version'], $comment['schemaVersion'], $comment['comment'], $comment['replies']);

        return new self(
            comment: $comment,
            replies: CommentReply::collect(is_iterable($replies) ? $replies : []),
            schemaVersion: (int) ($data['version'] ?? $data['schemaVersion'] ?? self::SCHEMA_VERSION),
        );
    }

    /** @param  array<string, mixed>  $comment */
    public static function fromComment(array $comment): self
    {
        return self::fromArray([
            'version' => self::SCHEMA_VERSION,
            'comment' => $comment,
            'replies' => $comment['replies'] ?? [],
        ]);
    }

    /** @return array{version: int, comment: array<string, mixed>, replies: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'comment' => $this->comment,
            'replies' => collect($this->replies)
                ->map(fn (CommentReply $reply): array => $reply->toArray())
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function toCommentArray(): array
    {
        return [
            ...$this->comment,
            'replies' => collect($this->replies)
                ->map(fn (CommentReply $reply): array => $reply->toArray())
                ->all(),
        ];
    }

    public function commentId(): string
    {
        return (string) ($this->comment['id'] ?? '');
    }

    public function fileId(): ?string
    {
        $fileId = $this->comment['fileId'] ?? null;

        return is_string($fileId) && $fileId !== '' ? $fileId : null;
    }
}

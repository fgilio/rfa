<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CommentAuthorType;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final readonly class CommentReply
{
    public function __construct(
        public string $id,
        public string $commentId,
        public CommentAuthorType $authorType,
        public string $authorKey,
        public ?string $authorLabel,
        public string $body,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $authorType = $data['author_type'] ?? $data['authorType'] ?? CommentAuthorType::Human;

        return new self(
            id: (string) ($data['id'] ?? ''),
            commentId: (string) ($data['comment_id'] ?? $data['commentId'] ?? ''),
            authorType: $authorType instanceof CommentAuthorType
                ? $authorType
                : CommentAuthorType::from((string) $authorType),
            authorKey: (string) ($data['author_key'] ?? $data['authorKey'] ?? 'rfa-ui'),
            authorLabel: self::stringOrNull($data['author_label'] ?? $data['authorLabel'] ?? null),
            body: (string) ($data['body'] ?? ''),
            createdAt: self::dateOrNull($data['created_at'] ?? $data['createdAt'] ?? null),
            updatedAt: self::dateOrNull($data['updated_at'] ?? $data['updatedAt'] ?? null),
        );
    }

    /**
     * @param  iterable<array<string, mixed>>  $replies
     * @return list<self>
     */
    public static function collect(iterable $replies): array
    {
        return collect($replies)
            ->map(fn (array $reply): self => self::fromArray($reply))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'commentId' => $this->commentId,
            'authorType' => $this->authorType->value,
            'authorKey' => $this->authorKey,
            'authorLabel' => $this->authorLabel,
            'body' => $this->body,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    public function toDatabaseArray(): array
    {
        return [
            'id' => $this->id,
            'comment_id' => $this->commentId,
            'author_type' => $this->authorType->value,
            'author_key' => $this->authorKey,
            'author_label' => $this->authorLabel,
            'body' => $this->body,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function wasEdited(): bool
    {
        return $this->createdAt !== null
            && $this->updatedAt !== null
            && $this->createdAt !== $this->updatedAt;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toISOString();
        }

        return Carbon::parse((string) $value)->toISOString();
    }
}

<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CommentAuthorType;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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
        $authorType = self::requiredAuthorType($data);
        $body = self::requiredString($data, 'body');
        $author = CommentAuthor::make(
            $authorType,
            self::requiredString($data, 'authorKey', 'author_key'),
            self::optionalString($data['author_label'] ?? $data['authorLabel'] ?? null, 'authorLabel'),
        );

        if (trim($body) === '') {
            throw new InvalidArgumentException('The body field must not be blank.');
        }

        return new self(
            id: self::requiredString($data, 'id'),
            commentId: self::requiredString($data, 'commentId', 'comment_id'),
            authorType: $author->type,
            authorKey: $author->key,
            authorLabel: $author->label,
            body: $body,
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

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $camelKey, ?string $snakeKey = null): string
    {
        $value = self::required($data, $camelKey, $snakeKey);

        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$camelKey} field must be a string.");
        }

        return $value;
    }

    /** @param  array<string, mixed>  $data */
    private static function requiredAuthorType(array $data): CommentAuthorType
    {
        $value = self::required($data, 'authorType', 'author_type');

        if ($value instanceof CommentAuthorType) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The authorType field must be a comment author type.');
        }

        return CommentAuthorType::tryFrom($value)
            ?? throw new InvalidArgumentException("Unsupported comment author type: {$value}.");
    }

    private static function optionalString(mixed $value, string $field): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("The {$field} field must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function required(array $data, string $camelKey, ?string $snakeKey = null): mixed
    {
        return match (true) {
            array_key_exists($camelKey, $data) => $data[$camelKey],
            $snakeKey !== null && array_key_exists($snakeKey, $data) => $data[$snakeKey],
            default => throw new InvalidArgumentException("The {$camelKey} field is required."),
        };
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

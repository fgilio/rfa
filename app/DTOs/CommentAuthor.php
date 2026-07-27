<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CommentAuthorType;
use InvalidArgumentException;

final readonly class CommentAuthor
{
    public const UI_KEY = 'rfa-ui';

    private function __construct(
        public CommentAuthorType $type,
        public string $key,
        public ?string $label,
    ) {}

    public static function human(): self
    {
        return self::make(CommentAuthorType::Human, self::UI_KEY);
    }

    public static function agent(string $key, ?string $label = null): self
    {
        return self::make(CommentAuthorType::Agent, $key, $label);
    }

    public static function make(CommentAuthorType $type, string $key, ?string $label = null): self
    {
        $key = trim($key);
        $label = $label === null ? null : trim($label);

        if ($key === '' || mb_strlen($key) > 100) {
            throw new InvalidArgumentException('The comment author key must contain between 1 and 100 characters.');
        }

        if ($label !== null && mb_strlen($label) > 100) {
            throw new InvalidArgumentException('The comment author label may not exceed 100 characters.');
        }

        return new self($type, $key, $label === '' ? null : $label);
    }

    /** @return array{author_type: string, author_key: string, author_label: ?string} */
    public function toDatabaseArray(): array
    {
        return [
            'author_type' => $this->type->value,
            'author_key' => $this->key,
            'author_label' => $this->label,
        ];
    }

    /** @return array{type: string, key: string, label: ?string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'key' => $this->key,
            'label' => $this->label,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DTOs;

class SourceText
{
    public const STATUS_LOADED = 'loaded';

    public const STATUS_NONE = 'none';

    public const STATUS_MISSING = 'missing';

    public const STATUS_TOO_LARGE = 'too-large';

    public function __construct(
        public readonly FileSourceSpec $source,
        public readonly string $status,
        public readonly ?string $content = null,
        public readonly ?int $byteSize = null,
        public readonly ?string $skipReason = null,
    ) {}

    public static function loaded(FileSourceSpec $source, string $content): self
    {
        return new self(
            source: $source,
            status: self::STATUS_LOADED,
            content: $content,
            byteSize: strlen($content),
        );
    }

    public static function none(FileSourceSpec $source): self
    {
        return new self(source: $source, status: self::STATUS_NONE);
    }

    public static function missing(FileSourceSpec $source): self
    {
        return new self(source: $source, status: self::STATUS_MISSING);
    }

    public static function tooLarge(FileSourceSpec $source, int $byteSize): self
    {
        return new self(
            source: $source,
            status: self::STATUS_TOO_LARGE,
            byteSize: $byteSize,
            skipReason: 'source-too-large',
        );
    }

    public function isLoaded(): bool
    {
        return $this->status === self::STATUS_LOADED;
    }

    public function isMissing(): bool
    {
        return $this->status === self::STATUS_MISSING;
    }

    public function isTooLarge(): bool
    {
        return $this->status === self::STATUS_TOO_LARGE;
    }

    /** @return array{source: array{type: string, ref: ?string, path: ?string, absolutePath: ?string}, status: string, content: ?string, byteSize: ?int, skipReason: ?string} */
    public function toArray(): array
    {
        return [
            'source' => $this->source->toArray(),
            'status' => $this->status,
            'content' => $this->content,
            'byteSize' => $this->byteSize,
            'skipReason' => $this->skipReason,
        ];
    }
}

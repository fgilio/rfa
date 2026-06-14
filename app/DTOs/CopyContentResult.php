<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Outcome of a copy-to-clipboard request for a file's diff or one side.
 *
 * A copy can succeed, find nothing to copy, or refuse a source past the
 * size cap. The caller needs that distinction to tell the user why a
 * click produced no clipboard write instead of failing silently.
 */
final readonly class CopyContentResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_TOO_LARGE = 'too-large';

    public function __construct(
        public string $status,
        public ?string $content = null,
        public ?int $byteSize = null,
    ) {}

    public static function ok(string $content): self
    {
        return new self(status: self::STATUS_OK, content: $content);
    }

    public static function unavailable(): self
    {
        return new self(status: self::STATUS_UNAVAILABLE);
    }

    public static function tooLarge(?int $byteSize): self
    {
        return new self(status: self::STATUS_TOO_LARGE, byteSize: $byteSize);
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /** @return array{status: string, content: ?string, byteSize: ?int} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'content' => $this->content,
            'byteSize' => $this->byteSize,
        ];
    }
}

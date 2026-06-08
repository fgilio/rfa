<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\GitRef;

class FileSourceSpec
{
    public const TYPE_NONE = 'none';

    public const TYPE_GIT = 'git';

    public const TYPE_ABSOLUTE = 'absolute';

    public function __construct(
        public readonly string $type,
        public readonly ?string $ref = null,
        public readonly ?string $path = null,
        public readonly ?string $absolutePath = null,
    ) {}

    public static function none(): self
    {
        return new self(type: self::TYPE_NONE);
    }

    public static function git(string $ref, string $path): self
    {
        return new self(type: self::TYPE_GIT, ref: $ref, path: $path);
    }

    public static function working(string $path): self
    {
        return self::git(GitRef::Working->value, $path);
    }

    public static function index(string $path): self
    {
        return self::git(GitRef::Index->value, $path);
    }

    public static function absolute(string $absolutePath): self
    {
        return new self(type: self::TYPE_ABSOLUTE, absolutePath: $absolutePath);
    }

    public function isNone(): bool
    {
        return $this->type === self::TYPE_NONE;
    }

    /** @return array{type: string, ref: ?string, path: ?string, absolutePath: ?string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'ref' => $this->ref,
            'path' => $this->path,
            'absolutePath' => $this->absolutePath,
        ];
    }
}

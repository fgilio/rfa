<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DiffSide;
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

    public static function absolute(string $absolutePath): self
    {
        return new self(type: self::TYPE_ABSOLUTE, absolutePath: $absolutePath);
    }

    /**
     * Resolve the git source for one side of a diff target.
     *
     * The left side reads the target's from-ref, following a rename
     * through $oldPath when one is given. Every other side reads the
     * to-ref, or the working copy when the target compares against the
     * working tree. Status-driven absent sides (added/deleted/external)
     * are the concern of {@see self::pairFor()}, not this resolver.
     */
    public static function forSide(
        DiffTarget $target,
        DiffSide $side,
        string $path,
        ?string $oldPath = null,
    ): self {
        if ($side === DiffSide::Left) {
            return self::git($target->from(), $oldPath ?? $path);
        }

        return $target->to() === null
            ? self::working($path)
            : self::git($target->to(), $path);
    }

    /**
     * Resolve the old- and new-side sources for a file under a diff target.
     *
     * Added and untracked files have no old side, deleted files have no
     * new side, and external files resolve to their absolute on-disk path.
     * Everything else maps to the target's from/to refs, following a
     * rename through $oldPath when one is given.
     *
     * @return array{0: self, 1: self}
     */
    public static function pairFor(
        DiffTarget $target,
        string $path,
        string $status,
        ?string $oldPath = null,
        bool $isUntracked = false,
        bool $isExternal = false,
        ?string $externalAbsolutePath = null,
    ): array {
        if ($isExternal && $externalAbsolutePath !== null && $externalAbsolutePath !== '') {
            return [self::none(), self::absolute($externalAbsolutePath)];
        }

        $oldSource = $status === 'added' || $isUntracked
            ? self::none()
            : self::forSide($target, DiffSide::Left, $path, $oldPath);

        $newSource = $status === 'deleted'
            ? self::none()
            : self::forSide($target, DiffSide::Right, $path);

        return [$oldSource, $newSource];
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

<?php

declare(strict_types=1);

namespace App\DTOs;

class FileDiff
{
    public function __construct(
        public readonly string $path,
        public readonly string $status, // 'modified', 'added', 'deleted', 'renamed', 'binary'
        public readonly ?string $oldPath,
        /** @var Hunk[] */
        public readonly array $hunks,
        public readonly int $additions,
        public readonly int $deletions,
        public readonly bool $isBinary = false,
        public readonly bool $isSymlink = false,
        public readonly ?string $symlinkTarget = null,
    ) {}

    /** @param Hunk[] $hunks */
    public function withHunks(array $hunks): self
    {
        return new self(
            path: $this->path,
            status: $this->status,
            oldPath: $this->oldPath,
            hunks: $hunks,
            additions: $this->additions,
            deletions: $this->deletions,
            isBinary: $this->isBinary,
            isSymlink: $this->isSymlink,
            symlinkTarget: $this->symlinkTarget,
        );
    }

    /** @return array<string, mixed> */
    public static function emptyArray(
        string $path,
        string $status,
        bool $tooLarge,
        ?string $skipReason = null,
    ): array {
        return [
            'path' => $path,
            'status' => $status,
            'oldPath' => null,
            'hunks' => [],
            'additions' => 0,
            'deletions' => 0,
            'isBinary' => false,
            'isSymlink' => false,
            'symlinkTarget' => null,
            'tooLarge' => $tooLarge,
            'skipReason' => $skipReason,
            // Cache-shape markers: skip results (too-large/empty/no-parse) must
            // carry the same keys DiffCacheKey::isCurrentShape() asserts, or they
            // fail validation on every read and re-spawn git forever. Callers add
            // syntaxStyles/headingsAnnotated; the rest live here.
            'tableAligned' => true,
            'newFileLineCount' => null,
            'gridLayout' => true,
            'lineTypesAreEnum' => true,
            'renameAware' => true,
            'syntaxHighlighter' => 'none',
        ];
    }

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, isSymlink: bool, symlinkTarget: ?string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status,
            'oldPath' => $this->oldPath,
            'hunks' => array_map(fn (Hunk $hunk) => $hunk->toArray(), $this->hunks),
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'isBinary' => $this->isBinary,
            'isSymlink' => $this->isSymlink,
            'symlinkTarget' => $this->symlinkTarget,
        ];
    }
}

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
        );
    }

    /** @return array<string, mixed> */
    public static function emptyArray(string $path, string $status, bool $tooLarge): array
    {
        return [
            'path' => $path,
            'status' => $status,
            'oldPath' => null,
            'hunks' => [],
            'additions' => 0,
            'deletions' => 0,
            'isBinary' => false,
            'tooLarge' => $tooLarge,
        ];
    }

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool} */
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
        ];
    }
}

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

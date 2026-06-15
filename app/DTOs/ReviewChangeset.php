<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A normalized changeset: the file list plus the metadata an export or
 * snapshot consumes as one unit.
 */
class ReviewChangeset
{
    /**
     * @param  list<FileListEntry>  $files
     */
    public function __construct(
        public readonly string $repoPath,
        public readonly string $sourceLabel,
        public readonly DiffTarget $target,
        public readonly array $files,
    ) {}

    public function fileCount(): int
    {
        return count($this->files);
    }

    public function hasFiles(): bool
    {
        return $this->files !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filesToArray(): array
    {
        return array_map(
            fn (FileListEntry $file): array => $file->toArray(),
            $this->files,
        );
    }

    /**
     * @return array{
     *     repoPath: string,
     *     sourceLabel: string,
     *     target: array{from: string, to: ?string},
     *     files: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'repoPath' => $this->repoPath,
            'sourceLabel' => $this->sourceLabel,
            'target' => $this->target->toArray(),
            'files' => $this->filesToArray(),
        ];
    }
}

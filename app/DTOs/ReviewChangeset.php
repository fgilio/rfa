<?php

declare(strict_types=1);

namespace App\DTOs;

class ReviewChangeset
{
    /**
     * @param  list<FileListEntry>  $files
     * @param  list<string>  $warnings
     * @param  list<array{path: string, reason: string}>  $skippedFiles
     */
    public function __construct(
        public readonly string $repoPath,
        public readonly string $sourceLabel,
        public readonly DiffTarget $target,
        public readonly array $files,
        public readonly array $warnings = [],
        public readonly array $skippedFiles = [],
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
     *     files: list<array<string, mixed>>,
     *     warnings: list<string>,
     *     skippedFiles: list<array{path: string, reason: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'repoPath' => $this->repoPath,
            'sourceLabel' => $this->sourceLabel,
            'target' => $this->target->toArray(),
            'files' => $this->filesToArray(),
            'warnings' => $this->warnings,
            'skippedFiles' => $this->skippedFiles,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ReviewSnapshot
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  list<array<string, mixed>>  $comments
     * @param  list<string>  $reviewedFileIds
     * @param  array<string, mixed>  $reviewedFiles
     */
    public function __construct(
        public ?string $repoPath,
        public string $sourceLabel,
        public DiffTarget $target,
        public array $files,
        public array $comments = [],
        public array $reviewedFileIds = [],
        public array $reviewedFiles = [],
        public string $globalComment = '',
        public ?string $exportedAt = null,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /**
     * @return array{
     *     schemaVersion: int,
     *     repoPath: ?string,
     *     sourceLabel: string,
     *     target: array{from: string, to: ?string},
     *     files: list<array<string, mixed>>,
     *     comments: list<array<string, mixed>>,
     *     reviewedFileIds: list<string>,
     *     reviewedFiles: array<string, mixed>,
     *     globalComment: string,
     *     exportedAt: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'repoPath' => $this->repoPath,
            'sourceLabel' => $this->sourceLabel,
            'target' => $this->target->toArray(),
            'files' => $this->files,
            'comments' => $this->comments,
            'reviewedFileIds' => $this->reviewedFileIds,
            'reviewedFiles' => $this->reviewedFiles,
            'globalComment' => $this->globalComment,
            'exportedAt' => $this->exportedAt,
        ];
    }
}

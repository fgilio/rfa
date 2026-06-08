<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ReviewState
{
    public const EMPTY_NONE = 'none';

    public const EMPTY_NO_FILES = 'no-files';

    public const EMPTY_FILTER = 'filter';

    public const EMPTY_ALL_REVIEWED = 'all-reviewed';

    public const EMPTY_HIDDEN_BY_REVIEWED = 'hidden-by-reviewed';

    /**
     * @param  list<array<string, mixed>>  $sourceFiles
     * @param  list<array<string, mixed>>  $visibleFiles
     * @param  array<string, mixed>|null  $selectedFile
     * @param  list<string>  $reviewedFileIds
     * @param  array<string, bool>  $reviewedFileMap
     * @param  list<array{id: string, path: string}>  $sourceFileEntries
     * @param  array<string, array{path: string, badgeLabel: string, badgeClass: string}>  $filesById
     * @param  array<string, int>  $countsByStatus
     */
    public function __construct(
        public array $sourceFiles,
        public array $visibleFiles,
        public ?array $selectedFile,
        public ?string $selectedFileId,
        public ?string $previousFileId,
        public ?string $nextFileId,
        public array $reviewedFileIds,
        public array $reviewedFileMap,
        public array $sourceFileEntries,
        public array $filesById,
        public array $countsByStatus,
        public int $totalFileCount,
        public int $visibleFileCount,
        public int $reviewedFileCount,
        public int $unreviewedFileCount,
        public int $additions,
        public int $deletions,
        public string $emptyStateReason = self::EMPTY_NONE,
    ) {}

    public function hasVisibleFiles(): bool
    {
        return $this->visibleFileCount > 0;
    }

    /**
     * @return array{
     *     sourceFiles: list<array<string, mixed>>,
     *     visibleFiles: list<array<string, mixed>>,
     *     selectedFile: ?array<string, mixed>,
     *     selectedFileId: ?string,
     *     previousFileId: ?string,
     *     nextFileId: ?string,
     *     reviewedFileIds: list<string>,
     *     reviewedFileMap: array<string, bool>,
     *     sourceFileEntries: list<array{id: string, path: string}>,
     *     filesById: array<string, array{path: string, badgeLabel: string, badgeClass: string}>,
     *     countsByStatus: array<string, int>,
     *     totalFileCount: int,
     *     visibleFileCount: int,
     *     reviewedFileCount: int,
     *     unreviewedFileCount: int,
     *     additions: int,
     *     deletions: int,
     *     emptyStateReason: string
     * }
     */
    public function toArray(): array
    {
        return [
            'sourceFiles' => $this->sourceFiles,
            'visibleFiles' => $this->visibleFiles,
            'selectedFile' => $this->selectedFile,
            'selectedFileId' => $this->selectedFileId,
            'previousFileId' => $this->previousFileId,
            'nextFileId' => $this->nextFileId,
            'reviewedFileIds' => $this->reviewedFileIds,
            'reviewedFileMap' => $this->reviewedFileMap,
            'sourceFileEntries' => $this->sourceFileEntries,
            'filesById' => $this->filesById,
            'countsByStatus' => $this->countsByStatus,
            'totalFileCount' => $this->totalFileCount,
            'visibleFileCount' => $this->visibleFileCount,
            'reviewedFileCount' => $this->reviewedFileCount,
            'unreviewedFileCount' => $this->unreviewedFileCount,
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'emptyStateReason' => $this->emptyStateReason,
        ];
    }
}

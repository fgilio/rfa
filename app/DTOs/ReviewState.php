<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Str;

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
     * @param  list<string>  $reviewedFileIds
     * @param  array<string, bool>  $reviewedFileMap
     * @param  list<array{id: string, path: string}>  $sourceFileEntries
     * @param  list<array{id: string, path: string}>  $visibleFileEntries
     * @param  array<string, bool>  $visibleFileMap
     * @param  array<string, array{path: string, badgeLabel: string, badgeClass: string}>  $filesById
     */
    public function __construct(
        public array $sourceFiles,
        public array $visibleFiles,
        public ?string $selectedFileId,
        public array $reviewedFileIds,
        public array $reviewedFileMap,
        public array $sourceFileEntries,
        public array $visibleFileEntries,
        public array $visibleFileMap,
        public array $filesById,
        public int $totalFileCount,
        public int $visibleFileCount,
        public int $reviewedFileCount,
        public int $additions,
        public int $deletions,
        public string $emptyStateReason = self::EMPTY_NONE,
    ) {}

    public function hasVisibleFiles(): bool
    {
        return $this->visibleFileCount > 0;
    }

    /**
     * Whether a path matches the sidebar file filter. Owns the matching rule
     * (trimmed, multibyte case-insensitive substring) so every surface that
     * filters by path agrees with the visible file list.
     */
    public static function pathMatchesFilter(string $path, string $fileFilter): bool
    {
        $normalizedFilter = Str::lower(trim($fileFilter));

        return $normalizedFilter === '' || str_contains(Str::lower($path), $normalizedFilter);
    }

    /**
     * @return array{
     *     sourceFiles: list<array<string, mixed>>,
     *     visibleFiles: list<array<string, mixed>>,
     *     selectedFileId: ?string,
     *     reviewedFileIds: list<string>,
     *     reviewedFileMap: array<string, bool>,
     *     sourceFileEntries: list<array{id: string, path: string}>,
     *     visibleFileEntries: list<array{id: string, path: string}>,
     *     visibleFileMap: array<string, bool>,
     *     filesById: array<string, array{path: string, badgeLabel: string, badgeClass: string}>,
     *     totalFileCount: int,
     *     visibleFileCount: int,
     *     reviewedFileCount: int,
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
            'selectedFileId' => $this->selectedFileId,
            'reviewedFileIds' => $this->reviewedFileIds,
            'reviewedFileMap' => $this->reviewedFileMap,
            'sourceFileEntries' => $this->sourceFileEntries,
            'visibleFileEntries' => $this->visibleFileEntries,
            'visibleFileMap' => $this->visibleFileMap,
            'filesById' => $this->filesById,
            'totalFileCount' => $this->totalFileCount,
            'visibleFileCount' => $this->visibleFileCount,
            'reviewedFileCount' => $this->reviewedFileCount,
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'emptyStateReason' => $this->emptyStateReason,
        ];
    }
}

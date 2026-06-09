<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReviewFilePair;
use App\DTOs\ReviewState;
use Illuminate\Support\Str;

class ReviewStateService
{
    /**
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<string, mixed>  $reviewedFiles
     */
    public function derive(array $files, array $reviewedFiles = [], ?string $selectedFileId = null, string $fileFilter = '', bool $hideReviewed = false): ReviewState
    {
        $sourceFiles = $this->sourceFiles($files);
        $reviewedFileIds = $this->reviewedFileIds($sourceFiles, $reviewedFiles);
        $reviewedIdSet = array_fill_keys($reviewedFileIds, true);
        $normalizedFilter = Str::lower(trim($fileFilter));

        $visibleFiles = collect($sourceFiles)
            ->filter(fn (array $file): bool => $this->fileIsVisible($file, $reviewedIdSet, $normalizedFilter, $hideReviewed))
            ->values()
            ->all();

        $selectedFile = $this->selectedFile($visibleFiles, $selectedFileId);
        $selectedFileId = $selectedFile === null ? null : (string) $selectedFile['id'];
        [$previousFileId, $nextFileId] = $this->neighbors($visibleFiles, $selectedFileId);

        $totalFileCount = count($sourceFiles);
        $visibleFileCount = count($visibleFiles);
        $reviewedFileCount = count($reviewedFileIds);

        return new ReviewState(
            sourceFiles: $sourceFiles,
            visibleFiles: $visibleFiles,
            selectedFile: $selectedFile,
            selectedFileId: $selectedFileId,
            previousFileId: $previousFileId,
            nextFileId: $nextFileId,
            reviewedFileIds: $reviewedFileIds,
            reviewedFileMap: $reviewedIdSet,
            sourceFileEntries: $this->sourceFileEntries($sourceFiles),
            visibleFileEntries: $this->sourceFileEntries($visibleFiles),
            visibleFileMap: array_fill_keys(
                collect($visibleFiles)
                    ->pluck('id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->all(),
                true,
            ),
            filesById: $this->filesById($sourceFiles),
            countsByStatus: $this->countsByStatus($sourceFiles),
            totalFileCount: $totalFileCount,
            visibleFileCount: $visibleFileCount,
            reviewedFileCount: $reviewedFileCount,
            unreviewedFileCount: max(0, $totalFileCount - $reviewedFileCount),
            additions: (int) collect($sourceFiles)->sum(fn (array $file): int => (int) ($file['additions'] ?? 0)),
            deletions: (int) collect($sourceFiles)->sum(fn (array $file): int => (int) ($file['deletions'] ?? 0)),
            emptyStateReason: $this->emptyStateReason($totalFileCount, $visibleFileCount, $reviewedFileCount, $normalizedFilter, $hideReviewed),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    public function sourceFiles(array $files): array
    {
        return collect($files)
            ->reject(fn (array $file): bool => ReviewFilePair::extractBasename((string) ($file['path'] ?? '')) !== null)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sourceFiles
     * @param  array<string, mixed>  $reviewedFiles
     * @return list<string>
     */
    private function reviewedFileIds(array $sourceFiles, array $reviewedFiles): array
    {
        return collect($sourceFiles)
            ->filter(fn (array $file): bool => array_key_exists((string) ($file['path'] ?? ''), $reviewedFiles))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $file
     * @param  array<string, bool>  $reviewedIdSet
     */
    private function fileIsVisible(array $file, array $reviewedIdSet, string $normalizedFilter, bool $hideReviewed): bool
    {
        $id = (string) ($file['id'] ?? '');
        $path = (string) ($file['path'] ?? '');

        if ($normalizedFilter !== '' && ! str_contains(Str::lower($path), $normalizedFilter)) {
            return false;
        }

        if ($hideReviewed && isset($reviewedIdSet[$id])) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $visibleFiles
     * @return array<string, mixed>|null
     */
    private function selectedFile(array $visibleFiles, ?string $selectedFileId): ?array
    {
        if ($visibleFiles === []) {
            return null;
        }

        if ($selectedFileId === null) {
            return $visibleFiles[0];
        }

        return collect($visibleFiles)->firstWhere('id', $selectedFileId) ?? $visibleFiles[0];
    }

    /**
     * @param  list<array<string, mixed>>  $visibleFiles
     * @return array{0: ?string, 1: ?string}
     */
    private function neighbors(array $visibleFiles, ?string $selectedFileId): array
    {
        if ($selectedFileId === null) {
            return [null, null];
        }

        $ids = collect($visibleFiles)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $index = array_search($selectedFileId, $ids, true);
        if ($index === false) {
            return [null, null];
        }

        return [
            $ids[$index - 1] ?? null,
            $ids[$index + 1] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sourceFiles
     * @return list<array{id: string, path: string}>
     */
    private function sourceFileEntries(array $sourceFiles): array
    {
        return collect($sourceFiles)
            ->map(fn (array $file): array => [
                'id' => (string) ($file['id'] ?? ''),
                'path' => (string) ($file['path'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sourceFiles
     * @return array<string, array{path: string, badgeLabel: string, badgeClass: string}>
     */
    private function filesById(array $sourceFiles): array
    {
        return collect($sourceFiles)
            ->mapWithKeys(function (array $file): array {
                $status = (string) ($file['status'] ?? 'modified');

                return [
                    (string) ($file['id'] ?? '') => [
                        'path' => (string) ($file['path'] ?? ''),
                        'badgeLabel' => $this->badgeLabel($status),
                        'badgeClass' => $this->badgeClass($status),
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sourceFiles
     * @return array<string, int>
     */
    private function countsByStatus(array $sourceFiles): array
    {
        return collect($sourceFiles)
            ->countBy(fn (array $file): string => (string) ($file['status'] ?? 'modified'))
            ->all();
    }

    private function emptyStateReason(int $totalFileCount, int $visibleFileCount, int $reviewedFileCount, string $normalizedFilter, bool $hideReviewed): string
    {
        if ($totalFileCount === 0) {
            return ReviewState::EMPTY_NO_FILES;
        }

        if ($visibleFileCount > 0) {
            return ReviewState::EMPTY_NONE;
        }

        if ($normalizedFilter !== '') {
            return ReviewState::EMPTY_FILTER;
        }

        if ($hideReviewed && $reviewedFileCount >= $totalFileCount) {
            return ReviewState::EMPTY_ALL_REVIEWED;
        }

        return $hideReviewed ? ReviewState::EMPTY_HIDDEN_BY_REVIEWED : ReviewState::EMPTY_NONE;
    }

    private function badgeLabel(string $status): string
    {
        return match ($status) {
            'added' => 'A',
            'deleted' => 'D',
            'renamed' => 'R',
            'commented' => 'C',
            default => 'M',
        };
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            'added' => 'text-gh-green',
            'deleted' => 'text-gh-red',
            'commented' => 'text-gh-muted',
            default => 'text-gh-attention',
        };
    }
}

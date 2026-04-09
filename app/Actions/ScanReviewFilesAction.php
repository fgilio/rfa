<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\FileListEntry;
use App\DTOs\ReviewFilePair;
use Illuminate\Support\Facades\File;

final readonly class ScanReviewFilesAction
{
    /**
     * Scan the .rfa/ directory for review file pairs via direct filesystem access.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(string $repoPath): array
    {
        $rfaDir = $repoPath.'/.rfa';

        if (! File::isDirectory($rfaDir)) {
            return [];
        }

        $pairs = [];

        foreach (File::files($rfaDir) as $file) {
            $relativePath = '.rfa/'.$file->getFilename();
            $basename = ReviewFilePair::extractBasename($relativePath);

            if ($basename === null) {
                continue;
            }

            $ext = strtolower($file->getExtension());

            if (! isset($pairs[$basename])) {
                $pairs[$basename] = ['json' => null, 'md' => null];
            }

            if ($ext === 'json' || $ext === 'md') {
                $pairs[$basename][$ext] = $this->buildFileEntry($relativePath, $file->getSize());
            }
        }

        return collect($pairs)
            ->map(fn (array $pair, string $basename) => new ReviewFilePair(
                basename: $basename,
                jsonFile: $pair['json'],
                mdFile: $pair['md'],
                createdAt: ReviewFilePair::parseTimestamp($basename),
            ))
            ->sortByDesc(fn (ReviewFilePair $p) => $p->createdAt?->getTimestamp() ?? 0)
            ->values()
            ->map(fn (ReviewFilePair $p) => $p->toArray())
            ->all();
    }

    /** @return array<string, mixed> */
    private function buildFileEntry(string $path, int|false $size): array
    {
        return (new FileListEntry(
            path: $path,
            status: 'added',
            oldPath: null,
            additions: 0,
            deletions: 0,
            isBinary: false,
            isUntracked: true,
            fileSize: $size !== false ? $this->formatFileSize($size) : null,
        ))->toArray();
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return round($bytes / 1024, 1).' KB';
    }
}

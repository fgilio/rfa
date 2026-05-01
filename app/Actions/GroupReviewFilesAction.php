<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ReviewFilePair;

final readonly class GroupReviewFilesAction
{
    /**
     * Split a flat file list into review pairs and source files.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array{reviewPairs: array<int, array<string, mixed>>, sourceFiles: array<int, array<string, mixed>>}
     */
    public function handle(array $files): array
    {
        $pairs = [];
        $sourceFiles = [];

        foreach ($files as $file) {
            $basename = ReviewFilePair::extractBasename($file['path']);

            if ($basename === null) {
                $sourceFiles[] = $file;

                continue;
            }

            $pairs[$basename] = $file;
        }

        $reviewPairs = collect($pairs)
            ->map(fn (array $mdFile, string $basename) => new ReviewFilePair(
                basename: $basename,
                mdFile: $mdFile,
                createdAt: ReviewFilePair::parseTimestamp($basename),
            ))
            ->sortByDesc(fn (ReviewFilePair $p) => $p->createdAt?->getTimestamp() ?? 0)
            ->values()
            ->map(fn (ReviewFilePair $p) => $p->toArray())
            ->all();

        return [
            'reviewPairs' => $reviewPairs,
            'sourceFiles' => $sourceFiles,
        ];
    }
}

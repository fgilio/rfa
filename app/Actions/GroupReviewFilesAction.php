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

            $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));

            if (! isset($pairs[$basename])) {
                $pairs[$basename] = ['json' => null, 'md' => null];
            }

            if ($ext === 'json') {
                $pairs[$basename]['json'] = $file;
            } elseif ($ext === 'md') {
                $pairs[$basename]['md'] = $file;
            }
        }

        // Build ReviewFilePair DTOs, sort newest-first
        $reviewPairs = collect($pairs)
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

        return [
            'reviewPairs' => $reviewPairs,
            'sourceFiles' => $sourceFiles,
        ];
    }
}

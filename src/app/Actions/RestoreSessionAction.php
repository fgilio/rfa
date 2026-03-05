<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\Models\ReviewSession;

final readonly class RestoreSessionAction
{
    /**
     * @param  array<int, array<string, mixed>>  $currentFiles
     * @return array{comments: array<int, array<string, mixed>>, viewedFiles: array<int, string>, globalComment: string, orphanedPaths: array<int, string>}
     */
    public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, string $contextFingerprint = DiffTarget::WORKING_CONTEXT): array
    {
        $session = ReviewSession::firstOrCreate(
            ReviewSession::scopeKey($repoPath, $projectId, $contextFingerprint),
            ['repo_path' => $repoPath],
        );

        $currentPaths = collect($currentFiles)->pluck('path')->all();
        $fileIdMap = collect($currentFiles)->pluck('id', 'path')->all();

        // Restore viewed files - prune removed files
        /** @var array<int, string> $viewedFiles */
        $viewedFiles = $session->viewed_files ?? [];
        $viewedFiles = array_values(array_intersect($viewedFiles, $currentPaths));

        // Restore comments - remap fileId, generate deterministic ID for orphaned files
        /** @var array<int, array<string, mixed>> $savedComments */
        $savedComments = $session->comments ?? [];
        $orphanedPaths = [];
        $comments = collect($savedComments)
            ->map(function (array $c) use ($fileIdMap, &$orphanedPaths) {
                $path = $c['file'] ?? '';
                if (isset($fileIdMap[$path])) {
                    return array_merge($c, ['fileId' => $fileIdMap[$path]]);
                }
                if ($path !== '') {
                    $orphanedPaths[$path] = true;

                    return array_merge($c, ['fileId' => FileListEntry::idForPath($path)]);
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();

        $orphanedPaths = array_keys($orphanedPaths);

        return [
            'comments' => $comments,
            'viewedFiles' => $viewedFiles,
            'globalComment' => $session->global_comment ?? '',
            'orphanedPaths' => $orphanedPaths,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ReviewSession;

final readonly class RestoreSessionAction
{
    /**
     * @param  array<int, array<string, mixed>>  $currentFiles
     * @return array{comments: array<int, array<string, mixed>>, viewedFiles: array<int, string>, globalComment: string}
     */
    public function handle(string $repoPath, array $currentFiles, ?int $projectId = null): array
    {
        $key = $projectId ? ['project_id' => $projectId] : ['repo_path' => $repoPath];

        $session = ReviewSession::firstOrCreate($key, ['repo_path' => $repoPath]);

        $currentPaths = collect($currentFiles)->pluck('path')->all();
        $fileIdMap = collect($currentFiles)->pluck('id', 'path')->all();

        // Restore viewed files - prune removed files
        /** @var array<int, string> $viewedFiles */
        $viewedFiles = $session->viewed_files ?? [];
        $viewedFiles = array_values(array_intersect($viewedFiles, $currentPaths));

        // Restore comments - prune entries for files no longer in the diff, remap fileId
        /** @var array<int, array<string, mixed>> $savedComments */
        $savedComments = $session->comments ?? [];
        $comments = collect($savedComments)
            ->filter(fn (array $c) => isset($fileIdMap[$c['file'] ?? '']))
            ->map(fn (array $c) => array_merge($c, ['fileId' => $fileIdMap[$c['file']]]))
            ->values()
            ->all();

        return [
            'comments' => $comments,
            'viewedFiles' => $viewedFiles,
            'globalComment' => $session->global_comment ?? '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\Models\ReviewSession;
use App\Services\GitDiffService;

final readonly class RestoreSessionAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $currentFiles
     * @return array{comments: array<int, array<string, mixed>>, viewedFiles: array<string, string>, globalComment: string, orphanedPaths: array<int, string>}
     */
    public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, string $contextFingerprint = DiffTarget::WORKING_CONTEXT, ?DiffTarget $target = null): array
    {
        $session = ReviewSession::firstOrCreate(
            ReviewSession::scopeKey($repoPath, $projectId, $contextFingerprint),
            ['repo_path' => $repoPath],
        );

        $fileIdMap = [];
        $untrackedMap = [];
        $currentPathSet = [];
        foreach ($currentFiles as $f) {
            $fileIdMap[$f['path']] = $f['id'];
            $untrackedMap[$f['path']] = $f['isUntracked'] ?? false;
            $currentPathSet[$f['path']] = true;
        }

        // Restore viewed files
        /** @var array<int|string, string> $rawViewed */
        $rawViewed = $session->viewed_files ?? [];
        $isImmutable = $target !== null && $target->isImmutable();

        // Legacy compat: convert indexed array to associative with fresh fingerprints
        if ($rawViewed !== [] && array_is_list($rawViewed)) {
            $viewedFiles = [];
            foreach ($rawViewed as $path) {
                if (isset($currentPathSet[$path])) {
                    $viewedFiles[$path] = $isImmutable ? '' : $this->gitDiffService->fileDiffFingerprint($repoPath, $path, $untrackedMap[$path] ?? false, $target);
                }
            }
        } else {
            /** @var array<string, string> $viewedFiles */
            $viewedFiles = array_intersect_key($rawViewed, $currentPathSet);

            // Fingerprint validation (skip for immutable targets)
            if (! $isImmutable) {
                foreach ($viewedFiles as $path => $storedHash) {
                    $currentHash = $this->gitDiffService->fileDiffFingerprint($repoPath, $path, $untrackedMap[$path] ?? false, $target);
                    if ($storedHash !== '' && $currentHash !== '' && $storedHash !== $currentHash) {
                        unset($viewedFiles[$path]);
                    }
                }
            }
        }

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

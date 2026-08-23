<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\DTOs\ReviewChangeset;
use App\Models\Project;
use App\Services\ExternalFilesService;
use App\Services\GitDiffService;
use App\Services\ReviewConfigService;
use App\Support\DiffCacheKey;
use App\Support\FilePathSorter;

final readonly class GetFileListAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private ExternalFilesService $externalFilesService,
        private ReviewConfigService $reviewConfigService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
    {
        $target ??= DiffTarget::workingDirectory();
        $changeset = $this->changeset(
            repoPath: $repoPath,
            projectId: $projectId,
            globalGitignorePath: $globalGitignorePath,
            target: $target,
        );

        $files = $changeset->filesToArray();

        if ($clearCache && ! $target->isImmutable()) {
            $projectKey = $projectId ?? $repoPath;
            $reviewFingerprint = $this->reviewConfigService->resolve()->cacheFingerprint();

            collect($files)->each(function (array $file) use ($projectKey, $reviewFingerprint, $target): void {
                DiffCacheKey::forget($projectKey, $file['id'], $reviewFingerprint, $target->contextKey());
            });
        }

        return $files;
    }

    public function changeset(string $repoPath, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): ReviewChangeset
    {
        $target ??= DiffTarget::workingDirectory();

        $files = $this->gitDiffService->getFileList($repoPath, $globalGitignorePath, $target);

        if ($target->isWorkingDirectory() && $projectId !== null) {
            $project = Project::find($projectId);

            if ($project !== null) {
                $files = [
                    ...$files,
                    ...$this->externalFilesService->getEntries((array) ($project->external_paths ?? [])),
                ];
            }
        }

        usort($files, fn (FileListEntry $a, FileListEntry $b): int => FilePathSorter::compare($a->path, $b->path));

        return new ReviewChangeset(
            repoPath: $repoPath,
            sourceLabel: $target->contextKey(),
            target: $target,
            files: $files,
        );
    }
}

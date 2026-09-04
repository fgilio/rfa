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
use App\Support\PathGuard;

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
    public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null, ?string $onlyPath = null): array
    {
        $target ??= DiffTarget::workingDirectory();
        $changeset = $this->changeset(
            repoPath: $repoPath,
            projectId: $projectId,
            globalGitignorePath: $globalGitignorePath,
            target: $target,
            onlyPath: $onlyPath,
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

    public function changeset(string $repoPath, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null, ?string $onlyPath = null): ReviewChangeset
    {
        $target ??= DiffTarget::workingDirectory();

        if ($onlyPath !== null && ! PathGuard::isRelative($onlyPath)) {
            return new ReviewChangeset(
                repoPath: $repoPath,
                sourceLabel: $target->contextKey(),
                target: $target,
                files: [],
            );
        }

        $project = $projectId === null ? null : Project::find($projectId);
        $externalFile = $target->isWorkingDirectory() && $project !== null && $onlyPath !== null
            ? $this->externalFilesService->getEntry((array) ($project->external_paths ?? []), $onlyPath)
            : null;

        $files = $externalFile === null
            ? $this->gitDiffService->getFileList($repoPath, $globalGitignorePath, $target, $onlyPath)
            : [];

        if ($externalFile !== null) {
            $files[] = $externalFile;
        } elseif ($target->isWorkingDirectory() && $project !== null && $onlyPath === null) {
            $files = [
                ...$files,
                ...$this->externalFilesService->getEntries((array) ($project->external_paths ?? [])),
            ];
        }

        if ($files === [] && $target->isWorkingDirectory() && $onlyPath !== null) {
            $explicitFile = $this->gitDiffService->getWholeFileEntry($repoPath, $onlyPath);

            if ($explicitFile !== null) {
                $files[] = $explicitFile;
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

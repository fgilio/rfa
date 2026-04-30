<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Models\Project;
use App\Services\ExternalFilesService;
use App\Services\GitDiffService;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;

final readonly class GetFileListAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private ExternalFilesService $externalFilesService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
    {
        $target ??= DiffTarget::workingDirectory();

        $fileList = $this->gitDiffService->getFileList($repoPath, $globalGitignorePath, $target);
        $files = collect($fileList)
            ->map(fn ($entry): array => $entry->toArray())
            ->values()
            ->all();

        if ($target->isWorkingDirectory() && $projectId !== null) {
            $project = Project::find($projectId);

            if ($project !== null) {
                $external = collect($this->externalFilesService->getEntries((array) ($project->external_paths ?? [])))
                    ->map(fn ($entry): array => $entry->toArray())
                    ->all();

                $files = [...$files, ...$external];
            }
        }

        if ($clearCache && ! $target->isImmutable()) {
            $projectKey = $projectId ?? $repoPath;
            collect($files)->each(function (array $file) use ($projectKey, $target): void {
                Cache::forget(DiffCacheKey::for($projectKey, $file['id'], $target->contextKey()));
            });
        }

        return $files;
    }
}

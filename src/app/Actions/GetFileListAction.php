<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Services\GitDiffService;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;

final readonly class GetFileListAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
    {
        $target ??= DiffTarget::workingDirectory();

        $fileList = $this->gitDiffService->getFileList($repoPath, $globalGitignorePath, $target);

        $files = array_map(fn ($entry) => $entry->toArray(), $fileList);

        if ($clearCache && ! $target->isImmutable()) {
            $projectKey = $projectId ?? $repoPath;
            foreach ($files as $file) {
                Cache::forget(DiffCacheKey::for($projectKey, $file['id'], $target->contextKey(), 'light'));
                Cache::forget(DiffCacheKey::for($projectKey, $file['id'], $target->contextKey(), 'dark'));
            }
        }

        return $files;
    }
}

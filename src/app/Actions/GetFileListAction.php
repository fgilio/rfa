<?php

declare(strict_types=1);

namespace App\Actions;

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
    public function handle(string $repoPath, bool $clearCache = true): array
    {
        $fileList = $this->gitDiffService->getFileList($repoPath);

        $files = array_map(fn ($entry) => $entry->toArray(), $fileList);

        if ($clearCache) {
            foreach ($files as $file) {
                Cache::forget(DiffCacheKey::for($repoPath, $file['id']));
            }
        }

        return $files;
    }
}

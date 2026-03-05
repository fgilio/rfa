<?php

declare(strict_types=1);

namespace App\Support;

final class DiffCacheKey
{
    public static function for(int|string $projectIdOrRepoPath, string $fileId, string $contextKey = 'working', string $theme = 'light'): string
    {
        return 'rfa_diff_v5_'.hash('xxh128', $projectIdOrRepoPath.':'.$contextKey.':'.$fileId.':'.$theme);
    }
}

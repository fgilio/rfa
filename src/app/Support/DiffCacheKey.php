<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\DiffTarget;

final class DiffCacheKey
{
    public static function for(int|string $projectIdOrRepoPath, string $fileId, string $contextKey = DiffTarget::WORKING_CONTEXT, string $theme = 'light'): string
    {
        return 'rfa_diff_v5_'.hash('xxh128', $projectIdOrRepoPath.':'.$contextKey.':'.$fileId.':'.$theme);
    }
}

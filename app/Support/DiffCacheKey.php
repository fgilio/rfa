<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GitRef;

final class DiffCacheKey
{
    public static function for(int|string $projectIdOrRepoPath, string $fileId, string $contextKey = GitRef::Working->value): string
    {
        return 'rfa_diff_v6_'.hash('xxh128', $projectIdOrRepoPath.':'.$contextKey.':'.$fileId);
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

final class DiffCacheKey
{
    public static function for(string $repoPath, string $fileId): string
    {
        return 'rfa_diff_'.md5($repoPath.':'.$fileId);
    }
}

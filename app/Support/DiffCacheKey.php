<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GitRef;

final class DiffCacheKey
{
    /** Default for the implicit working-tree context ("HEAD..working"). Matches DiffTarget::workingDirectory()->contextKey(). */
    private const WORKING_TREE_CONTEXT = 'HEAD..'.GitRef::Working->value;

    public static function for(int|string $projectIdOrRepoPath, string $fileId, string $contextKey = self::WORKING_TREE_CONTEXT): string
    {
        return 'rfa_diff_v7_'.hash('xxh128', $projectIdOrRepoPath.':'.$contextKey.':'.$fileId);
    }
}

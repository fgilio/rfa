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
        return 'rfa_diff_v9_'.hash('xxh128', $projectIdOrRepoPath.':'.$contextKey.':'.$fileId);
    }

    /**
     * Each historical shape change adds a marker key; missing any means the
     * entry predates a format change and must be recomputed.
     *
     * @phpstan-assert-if-true array<string, mixed> $cached
     */
    public static function isCurrentShape(mixed $cached): bool
    {
        return is_array($cached)
            && array_key_exists('syntaxStyles', $cached)
            && array_key_exists('isSymlink', $cached)
            && array_key_exists('tableAligned', $cached)
            && array_key_exists('newFileLineCount', $cached)
            && array_key_exists('headingsAnnotated', $cached)
            && array_key_exists('gridLayout', $cached)
            && array_key_exists('lineTypesAreEnum', $cached)
            && array_key_exists('renameAware', $cached)
            && array_key_exists('syntaxHighlighter', $cached);
    }
}

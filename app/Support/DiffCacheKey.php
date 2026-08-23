<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GitRef;
use Illuminate\Support\Facades\Cache;

final class DiffCacheKey
{
    /** Default for the implicit working-tree context ("HEAD..working"). Matches DiffTarget::workingDirectory()->contextKey(). */
    private const WORKING_TREE_CONTEXT = 'HEAD..'.GitRef::Working->value;

    /**
     * Suffixes appended to a file's context key to cache distinct shapes of the
     * same diff. The empty string is the base diff; `:full-context` is the
     * fully-expanded diff ExpandDiffGapAction pulls gap lines from. Every cache
     * variant a file can hold MUST be listed here so {@see self::forget()}
     * invalidates all of them together. A variant missing from this list
     * survives invalidation and serves stale content.
     */
    public const VARIANTS = ['', ':full-context'];

    /**
     * @param  string  $reviewFingerprint  Effective review settings that shape the cached
     *                                     content, from ReviewConfig::movedLineFingerprint().
     *                                     Passing a raw config value here would let two runs
     *                                     with identical behavior land on different keys.
     */
    public static function for(int|string $projectIdOrRepoPath, string $fileId, string $reviewFingerprint, string $contextKey = self::WORKING_TREE_CONTEXT): string
    {
        return 'rfa_diff_v13_'.hash('xxh128', $projectIdOrRepoPath.':'.$contextKey.':'.$reviewFingerprint.':'.$fileId);
    }

    /**
     * Forget every cache variant of a file's diff for the given context. Use
     * this instead of forgetting a single {@see self::for()} key so new
     * variants (e.g. `:full-context`) can never be left stale.
     */
    public static function forget(int|string $projectIdOrRepoPath, string $fileId, string $reviewFingerprint, string $contextKey = self::WORKING_TREE_CONTEXT): void
    {
        foreach (self::VARIANTS as $variant) {
            Cache::forget(self::for($projectIdOrRepoPath, $fileId, $reviewFingerprint, $contextKey.$variant));
        }
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

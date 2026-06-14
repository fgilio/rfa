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

    public static function for(int|string $projectIdOrRepoPath, string $fileId, string $contextKey = self::WORKING_TREE_CONTEXT): string
    {
        return 'rfa_diff_v12_'.hash('xxh128', $projectIdOrRepoPath.':'.$contextKey.':'.self::movedLineFingerprint().':'.$fileId);
    }

    /**
     * Moved-line settings shape the cached hunk content: git colorizes moves
     * and the parser bakes those markers into the stored diff. They must vary
     * the key so flipping the setting cannot serve content computed under the
     * old one. The mode only matters while detection is on, so a disabled run
     * collapses to a single bucket.
     */
    private static function movedLineFingerprint(): string
    {
        // Keys are only built within a booted app in production. Pure-unit
        // callers (no config bound) get a stable bucket, which still preserves
        // every key relationship since the fingerprint is constant for them.
        if (! app()->bound('config')) {
            return 'm0';
        }

        // Read config directly rather than through ReviewConfigService: the
        // Support layer must not depend on Services, and a raw value is enough
        // to bucket the cache (the mode only matters while detection is on).
        if (! config('rfa.moved_lines.enabled', false)) {
            return 'm0';
        }

        return 'm1-'.(string) config('rfa.moved_lines.mode', 'zebra');
    }

    /**
     * Forget every cache variant of a file's diff for the given context. Use
     * this instead of forgetting a single {@see self::for()} key so new
     * variants (e.g. `:full-context`) can never be left stale.
     */
    public static function forget(int|string $projectIdOrRepoPath, string $fileId, string $contextKey = self::WORKING_TREE_CONTEXT): void
    {
        foreach (self::VARIANTS as $variant) {
            Cache::forget(self::for($projectIdOrRepoPath, $fileId, $contextKey.$variant));
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

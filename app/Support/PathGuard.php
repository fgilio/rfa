<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class PathGuard
{
    /**
     * Assert that a path is relative and contains no traversal segments.
     *
     * @throws InvalidArgumentException
     */
    public static function assertRelative(string $path): void
    {
        $normalizedPath = str_replace('\\', '/', $path);

        throw_if(
            $path === ''
                || Str::startsWith($normalizedPath, '/')
                || preg_match('/^[A-Za-z]:\//', $normalizedPath) === 1
                || in_array('..', explode('/', $normalizedPath), true),
            InvalidArgumentException::class,
            "Invalid file path: {$path}",
        );
    }

    /**
     * Assert a repo-relative path resolves to a location *inside* the repo.
     *
     * `assertRelative` blocks lexical escapes (`..`, absolute, drive-rooted) but
     * not a symlinked path component that points outside the tree. This is the vector
     * behind writing through a symlink to clobber a file outside the repo. This
     * resolves the deepest existing ancestor of the target's *parent directory*
     * (the leaf itself may be a symlink we're about to replace, and may not exist
     * yet on a restore) and confirms that directory stays under the repo's real
     * path, so a symlinked intermediate component can't redirect the write out.
     *
     * Lenient when the repo root can't be resolved (e.g. a synthetic test path):
     * `assertRelative` remains the guarantee in that case.
     *
     * @throws InvalidArgumentException
     */
    public static function assertWithinRepo(string $repoPath, string $relativePath): void
    {
        self::assertRelative($relativePath);

        $repoReal = realpath($repoPath);
        if ($repoReal === false) {
            return;
        }

        // Walk up from the target's parent to the deepest ancestor that exists,
        // resolving symlinks via realpath (which also reports existence, so no raw
        // file_exists is needed). A symlinked intermediate component resolves to
        // its real location here, exposing an escape.
        $ancestor = dirname($repoReal.DIRECTORY_SEPARATOR.str_replace('\\', '/', $relativePath));
        $ancestorReal = realpath($ancestor);
        while ($ancestorReal === false && $ancestor !== dirname($ancestor)) {
            $ancestor = dirname($ancestor);
            $ancestorReal = realpath($ancestor);
        }

        if ($ancestorReal === false) {
            return;
        }

        throw_unless(
            $ancestorReal === $repoReal
                || str_starts_with($ancestorReal.DIRECTORY_SEPARATOR, $repoReal.DIRECTORY_SEPARATOR),
            InvalidArgumentException::class,
            "Path escapes the repository: {$relativePath}",
        );
    }

    /**
     * Resolve a repo-relative path for local reads.
     *
     * When `$followLeaf` is true, the leaf must already exist and any symlink
     * target must still resolve inside the repository. When false, only the
     * parent path is checked; this is useful for inspecting symlink metadata
     * without following the link target.
     */
    public static function resolveWithinRepo(string $repoPath, string $relativePath, bool $followLeaf = true): ?string
    {
        self::assertRelative($relativePath);

        $repoReal = realpath($repoPath);
        if ($repoReal === false) {
            return null;
        }

        $absolutePath = $repoReal.DIRECTORY_SEPARATOR.str_replace('\\', '/', $relativePath);

        if (! $followLeaf) {
            self::assertWithinRepo($repoReal, $relativePath);

            return $absolutePath;
        }

        $pathReal = realpath($absolutePath);
        if ($pathReal === false) {
            return null;
        }

        throw_unless(
            $pathReal === $repoReal
                || str_starts_with($pathReal.DIRECTORY_SEPARATOR, $repoReal.DIRECTORY_SEPARATOR),
            InvalidArgumentException::class,
            "Path escapes the repository: {$relativePath}",
        );

        return $pathReal;
    }
}

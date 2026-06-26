<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Orders file paths the way file trees do: within any directory, sub-folders
 * come before loose files ("folders-first"), then alphabetically by segment.
 *
 * Git's own `diff --name-status` output (which the file list inherits) is a
 * flat byte-wise sort of the full path, so a loose file sorts against sibling
 * directory names by its next character - e.g. `Content/CLAUDE.md` lands ahead
 * of `Content/Jobs/...` because `C` < `J`. That reads as out of place to anyone
 * used to VS Code or GitHub's PR file tree, both of which list folders first.
 * This comparator restores that expectation while keeping a single flat list.
 */
final class FilePathSorter
{
    /**
     * Compare two paths folders-first. At the first differing segment, a path
     * that still has directories below it wins over one whose segment is a
     * leaf; otherwise the segments are compared byte-wise, matching git.
     */
    public static function compare(string $a, string $b): int
    {
        $segmentsA = explode('/', $a);
        $segmentsB = explode('/', $b);
        $countA = count($segmentsA);
        $countB = count($segmentsB);
        $shared = min($countA, $countB);

        for ($i = 0; $i < $shared; $i++) {
            if ($segmentsA[$i] === $segmentsB[$i]) {
                continue;
            }

            $aIsDirectory = $i < $countA - 1;
            $bIsDirectory = $i < $countB - 1;

            if ($aIsDirectory !== $bIsDirectory) {
                return $aIsDirectory ? -1 : 1;
            }

            return strcmp($segmentsA[$i], $segmentsB[$i]);
        }

        // One path is a prefix of the other (e.g. `a/b` vs `a/b/c`); the
        // shallower one sorts first. Distinct files never nest this way, but
        // handle it deterministically regardless.
        return $countA <=> $countB;
    }
}

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
     * Compare two paths folders-first. At each segment a path that still has
     * directories below it sorts before one whose segment is a leaf; otherwise
     * the segments are compared byte-wise, matching git.
     */
    public static function compare(string $a, string $b): int
    {
        $segmentsA = explode('/', $a);
        $segmentsB = explode('/', $b);
        $countA = count($segmentsA);
        $countB = count($segmentsB);
        $shared = min($countA, $countB);

        for ($i = 0; $i < $shared; $i++) {
            $aIsDirectory = $i < $countA - 1;
            $bIsDirectory = $i < $countB - 1;

            // Folder beats file the moment the paths part ways - including when
            // the segment names match but one path descends further (a file `a`
            // replaced by a directory `a/...`). Settling this at the divergence
            // point rather than via a post-loop length fallback keeps the order
            // a true total order: a length tiebreaker disagrees with this rule
            // (`b/a` < `a`, yet `a` < `a/a`), which makes `usort` non-transitive
            // and the file list order depend on git's incoming order.
            if ($aIsDirectory !== $bIsDirectory) {
                return $aIsDirectory ? -1 : 1;
            }

            if ($segmentsA[$i] !== $segmentsB[$i]) {
                return strcmp($segmentsA[$i], $segmentsB[$i]);
            }
        }

        // Reached only when every segment matched in both name and depth, i.e.
        // the paths are identical.
        return $countA <=> $countB;
    }
}

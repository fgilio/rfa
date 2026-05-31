<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Re-locates a comment's anchor after its file drifts.
 *
 * Both comment-anchor resolvers (review and context) face the same problem:
 * a stored line snippet that no longer sits at its original line number
 * because the file changed around it. This service finds the snippet in the
 * current content and returns the line range it now occupies, preferring the
 * occurrence closest to the original line so nearby drift wins over a
 * coincidental match elsewhere.
 *
 * The recovered range spans the SNIPPET's length, not the comment's original
 * line span — those differ when the captured snippet skipped rows (collapsed
 * gaps, missing DOM cells), and using the original span would anchor the end
 * past where the snippet actually matched (potentially past end-of-file).
 */
final class LineSnippetMatcherService
{
    /**
     * Find $snippet in $content and return its new [startLine, endLine]
     * (1-based, inclusive) at the occurrence closest to $originalStartLine,
     * or null when the snippet can't be recovered.
     *
     * @return array{0: int, 1: int}|null
     */
    public function shiftedLines(string $content, ?string $snippet, ?int $originalStartLine): ?array
    {
        if ($snippet === null || $snippet === '' || $originalStartLine === null) {
            return null;
        }

        $fileLines = explode("\n", $content);
        $snippetLines = explode("\n", $snippet);
        $snippetLen = count($snippetLines);
        $haystackLen = count($fileLines);

        if ($snippetLen > $haystackLen) {
            return null;
        }

        $needle = rtrim($snippet);
        $matches = [];
        for ($i = 0; $i <= $haystackLen - $snippetLen; $i++) {
            $candidate = array_slice($fileLines, $i, $snippetLen);
            if (rtrim(implode("\n", $candidate)) === $needle) {
                $matches[] = $i + 1;
            }
        }

        if ($matches === []) {
            return null;
        }

        $closest = collect($matches)
            ->sortBy(fn (int $n): int => abs($n - $originalStartLine))
            ->first();

        return [$closest, $closest + $snippetLen - 1];
    }
}

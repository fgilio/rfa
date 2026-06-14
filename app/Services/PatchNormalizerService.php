<?php

declare(strict_types=1);

namespace App\Services;

class PatchNormalizerService
{
    public function normalize(string $patch): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $patch));

        $oldHeaderPath = null;
        $newHeaderPath = null;
        $oldBodyPath = null;
        $newBodyPath = null;
        $insideFileHeader = false;

        return implode("\n", array_map(function (string $line) use (&$oldHeaderPath, &$newHeaderPath, &$oldBodyPath, &$newBodyPath, &$insideFileHeader): string {
            if (str_starts_with($line, 'diff --git ')) {
                $insideFileHeader = true;

                $paths = $this->parseDiffGitPaths($line);
                if ($paths === null) {
                    $oldHeaderPath = $newHeaderPath = $oldBodyPath = $newBodyPath = null;

                    return $line;
                }

                [$oldHeaderPath, $newHeaderPath] = $paths;
                [$oldBodyPath, $newBodyPath] = $this->bodyPaths($oldHeaderPath, $newHeaderPath);

                return "diff --git a/{$oldBodyPath} b/{$newBodyPath}";
            }

            if ($insideFileHeader && str_starts_with($line, '@@ ')) {
                $insideFileHeader = false;

                return $line;
            }

            if ($insideFileHeader && str_starts_with($line, '--- ')) {
                return $this->normalizeFileMarker($line, '--- ', 'a/', $oldHeaderPath, $oldBodyPath);
            }

            if ($insideFileHeader && str_starts_with($line, '+++ ')) {
                return $this->normalizeFileMarker($line, '+++ ', 'b/', $newHeaderPath, $newBodyPath);
            }

            return $line;
        }, $lines));
    }

    /**
     * Extract the prefix-stripped old and new paths from a `diff --git`
     * header line. Prefers splits validated by matching remainders, so a
     * path that itself contains ` b/` does not split at the wrong spot.
     *
     * @return array{string, string}|null
     */
    public function headerPaths(string $line): ?array
    {
        if (! str_starts_with($line, 'diff --git ')) {
            return null;
        }

        $paths = $this->parseDiffGitPaths($line);
        if ($paths === null) {
            return null;
        }

        return $this->bodyPaths($paths[0], $paths[1]);
    }

    /**
     * Resolve old and new paths from a section's rename or copy markers.
     *
     * Each marker carries a single path alone on its line with git's C-style
     * quoting, so they resolve unambiguously where the combined `diff --git`
     * header cannot: git leaves spaces unquoted there and packs both paths
     * onto one line, so a path containing a space or a ` b/` substring has no
     * recoverable boundary. Returns null when the section is neither a rename
     * nor a copy.
     *
     * @param  list<string>  $lines  Lines of one file section, header first.
     * @return array{string, string}|null
     */
    public function renamePaths(array $lines): ?array
    {
        $old = null;
        $new = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, '@@ ')) {
                break;
            }

            if (str_starts_with($line, 'rename from ')) {
                $old = $this->decodePath(substr($line, strlen('rename from ')));
            } elseif (str_starts_with($line, 'copy from ')) {
                $old = $this->decodePath(substr($line, strlen('copy from ')));
            } elseif (str_starts_with($line, 'rename to ')) {
                $new = $this->decodePath(substr($line, strlen('rename to ')));
            } elseif (str_starts_with($line, 'copy to ')) {
                $new = $this->decodePath(substr($line, strlen('copy to ')));
            }
        }

        return $old !== null && $new !== null ? [$old, $new] : null;
    }

    /**
     * Unwrap the C-style quoting git applies to a path containing control
     * characters, a double quote, or a backslash. Unquoted tokens (the common
     * case, including plain spaces) pass through untouched.
     */
    private function decodePath(string $token): string
    {
        if (strlen($token) >= 2 && str_starts_with($token, '"') && str_ends_with($token, '"')) {
            return stripcslashes(substr($token, 1, -1));
        }

        return $token;
    }

    /**
     * @return array{string, string}|null
     */
    private function parseDiffGitPaths(string $line): ?array
    {
        $tail = substr($line, strlen('diff --git '));

        if (preg_match('/^"((?:\\\\.|[^"\\\\])*)" "((?:\\\\.|[^"\\\\])*)"$/', $tail, $matches) === 1) {
            return [stripcslashes($matches[1]), stripcslashes($matches[2])];
        }

        $splitPaths = $this->splitUnquotedPaths($tail);
        if ($splitPaths !== null) {
            return $splitPaths;
        }

        // Renames with spaces in both paths defeat the validated split above
        // (the two sides share no remainder), so fall back to the first ' b/'
        // boundary. Same-path headers never reach this branch.
        if (str_starts_with($tail, 'a/')) {
            $separator = strpos($tail, ' b/');
            if ($separator !== false) {
                return [substr($tail, 0, $separator), substr($tail, $separator + 1)];
            }
        }

        if (preg_match('/^(\S+) (\S+)$/', $tail, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return null;
    }

    /**
     * @return array{string, string}|null
     */
    private function splitUnquotedPaths(string $tail): ?array
    {
        $offset = 0;

        while (($position = strpos($tail, ' ', $offset)) !== false) {
            $oldPath = substr($tail, 0, $position);
            $newPath = substr($tail, $position + 1);

            if ($oldPath === $newPath) {
                return [$oldPath, $newPath];
            }

            $oldRemainder = $this->afterFirstSlash($oldPath);
            $newRemainder = $this->afterFirstSlash($newPath);

            if (
                $oldRemainder !== null
                && $oldRemainder === $newRemainder
                && $this->firstSegment($oldPath) !== $this->firstSegment($newPath)
            ) {
                return [$oldPath, $newPath];
            }

            $offset = $position + 1;
        }

        return null;
    }

    /**
     * @return array{string, string}
     */
    private function bodyPaths(string $oldPath, string $newPath): array
    {
        $oldFirstSegment = $this->firstSegment($oldPath);
        $newFirstSegment = $this->firstSegment($newPath);
        $oldAfterFirstSlash = $this->afterFirstSlash($oldPath);
        $newAfterFirstSlash = $this->afterFirstSlash($newPath);

        if (
            $oldFirstSegment === 'a'
            && $newFirstSegment === 'b'
            && $oldAfterFirstSlash !== null
            && $newAfterFirstSlash !== null
        ) {
            return [$oldAfterFirstSlash, $newAfterFirstSlash];
        }

        if (
            $oldAfterFirstSlash !== null
            && $oldAfterFirstSlash === $newAfterFirstSlash
            && $oldFirstSegment !== $newFirstSegment
        ) {
            return [$oldAfterFirstSlash, $newAfterFirstSlash];
        }

        return [$oldPath, $newPath];
    }

    private function normalizeFileMarker(string $line, string $marker, string $prefix, ?string $headerPath, ?string $bodyPath): string
    {
        $path = substr($line, strlen($marker));

        if ($path === '/dev/null') {
            return $line;
        }

        if ($headerPath === null || $bodyPath === null) {
            return $line;
        }

        if ($path === $headerPath || $path === $bodyPath) {
            return $marker.$prefix.$bodyPath;
        }

        return $line;
    }

    private function afterFirstSlash(string $path): ?string
    {
        $position = strpos($path, '/');

        if ($position === false || $position === strlen($path) - 1) {
            return null;
        }

        return substr($path, $position + 1);
    }

    private function firstSegment(string $path): string
    {
        $position = strpos($path, '/');

        return $position === false ? $path : substr($path, 0, $position);
    }
}

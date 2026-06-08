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
     * @return array{string, string}|null
     */
    private function parseDiffGitPaths(string $line): ?array
    {
        $tail = substr($line, strlen('diff --git '));

        if (preg_match('/^"((?:\\\\.|[^"\\\\])*)" "((?:\\\\.|[^"\\\\])*)"$/', $tail, $matches) === 1) {
            return [stripcslashes($matches[1]), stripcslashes($matches[2])];
        }

        if (preg_match('/^(\S+) (\S+)$/', $tail, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return null;
    }

    /**
     * @return array{string, string}
     */
    private function bodyPaths(string $oldPath, string $newPath): array
    {
        $oldBody = $this->stripSingleLetterPrefix($oldPath);
        $newBody = $this->stripSingleLetterPrefix($newPath);

        if ($oldBody !== $oldPath || $newBody !== $newPath) {
            return [$oldBody, $newBody];
        }

        $oldAfterFirstSlash = $this->afterFirstSlash($oldPath);
        $newAfterFirstSlash = $this->afterFirstSlash($newPath);

        if (
            $oldAfterFirstSlash !== null
            && $oldAfterFirstSlash === $newAfterFirstSlash
            && $this->firstSegment($oldPath) !== $this->firstSegment($newPath)
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

        if ($headerPath !== null && $bodyPath !== null && $path === $headerPath) {
            return $marker.$prefix.$bodyPath;
        }

        return $marker.$prefix.$this->stripSingleLetterPrefix($path);
    }

    private function stripSingleLetterPrefix(string $path): string
    {
        if (preg_match('#^[A-Za-z]/(.+)$#', $path, $matches) !== 1) {
            return $path;
        }

        return $matches[1];
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

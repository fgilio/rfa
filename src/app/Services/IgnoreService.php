<?php

namespace App\Services;

class IgnoreService
{
    private const ALWAYS_EXCLUDE = [
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        'bun.lock',
        'composer.lock',
    ];

    /** @return array<int, string> */
    public function getExcludePathspecs(string $repoPath): array
    {
        $patterns = self::ALWAYS_EXCLUDE;

        $ignoreFile = $repoPath.'/.rfaignore';
        if (file_exists($ignoreFile)) {
            $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $patterns[] = $line;
            }
        }

        // Convert to git pathspec exclude format
        // Bare filenames need **/ prefix to match in subdirectories
        return array_map(function (string $p): string {
            $needsGlob = ! str_contains($p, '/') && ! str_contains($p, '*');

            return $needsGlob
                ? ":(glob,exclude)**/{$p}"
                : ":(exclude){$p}";
        }, $patterns);
    }
}

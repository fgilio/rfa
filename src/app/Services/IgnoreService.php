<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

class IgnoreService
{
    private const ALWAYS_EXCLUDE = [
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
        'bun.lock',
        'composer.lock',
    ];

    /** @param array<int, string> $excludePatterns */
    public function isPathExcluded(string $path, array $excludePatterns): bool
    {
        foreach ($excludePatterns as $pattern) {
            // Strip pathspec prefix: :(exclude) or :(glob,exclude)**/
            $glob = preg_replace('/^:\([^)]+\)(\*\*\/)?/', '', $pattern);
            if (fnmatch($glob, $path) || fnmatch($glob, basename($path))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    public function getExcludePathspecs(string $repoPath): array
    {
        $patterns = self::ALWAYS_EXCLUDE;

        $ignoreFile = $repoPath.'/.rfaignore';
        if (File::exists($ignoreFile)) {
            $lines = explode("\n", File::get($ignoreFile));
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

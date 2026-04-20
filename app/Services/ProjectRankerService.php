<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

final class ProjectRankerService
{
    /**
     * Rank a project against a search query. Lower = better match. null = no match.
     *
     * Score = tier * 10 + field (field: 0=name, 1=branch, 2=path).
     * Tier 0: exact name. Tier 1: starts-with / word-boundary. Tier 2: substring.
     */
    public function rank(string $name, string $branch, string $path, string $query): ?int
    {
        $lowerQuery = Str::lower($query);
        $lowerName = Str::lower($name);
        $lowerBranch = Str::lower($branch);
        $lowerPath = Str::lower($path);
        $wordBoundaryPattern = '/(?:^|[^a-z0-9])'.preg_quote($lowerQuery, '/').'/';

        if ($lowerName === $lowerQuery) {
            return 0;
        }

        if (str_starts_with($lowerName, $lowerQuery) || preg_match($wordBoundaryPattern, $lowerName)) {
            return 10;
        }
        if (str_starts_with($lowerBranch, $lowerQuery) || preg_match($wordBoundaryPattern, $lowerBranch)) {
            return 11;
        }
        if (preg_match($wordBoundaryPattern, $lowerPath)) {
            return 12;
        }

        if (str_contains($lowerName, $lowerQuery)) {
            return 20;
        }
        if (str_contains($lowerBranch, $lowerQuery)) {
            return 21;
        }
        if (str_contains($lowerPath, $lowerQuery)) {
            return 22;
        }

        return null;
    }
}

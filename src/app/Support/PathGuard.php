<?php

declare(strict_types=1);

namespace App\Support;

final class PathGuard
{
    /**
     * Assert that a path is relative and contains no traversal segments.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertRelative(string $path): void
    {
        if (str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new \InvalidArgumentException("Invalid file path: {$path}");
        }
    }
}

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
        $segments = explode('/', str_replace('\\', '/', $path));

        if ($path === '' || str_starts_with($path, '/') || in_array('..', $segments, true)) {
            throw new \InvalidArgumentException("Invalid file path: {$path}");
        }
    }
}

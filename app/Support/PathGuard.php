<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class PathGuard
{
    /**
     * Assert that a path is relative and contains no traversal segments.
     *
     * @throws InvalidArgumentException
     */
    public static function assertRelative(string $path): void
    {
        $normalizedPath = str_replace('\\', '/', $path);

        throw_if(
            $path === '' || Str::startsWith($normalizedPath, '/') || in_array('..', explode('/', $normalizedPath), true),
            InvalidArgumentException::class,
            "Invalid file path: {$path}",
        );
    }
}

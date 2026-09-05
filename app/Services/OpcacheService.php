<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Thin wrapper over the opcache extension functions and the script list of
 * the running request.
 *
 * Exists so the warm-up service can be unit tested without a live opcache,
 * which the test CLI does not enable.
 */
class OpcacheService
{
    public function isEnabled(): bool
    {
        if (! function_exists('opcache_get_status')) {
            return false;
        }

        return @opcache_get_status(false) !== false;
    }

    /**
     * Absolute paths of every script the current request has loaded so far.
     *
     * Deliberately not opcache_get_status(): enumerating shared memory from a
     * built-in server worker costs hundreds of milliseconds, while this list
     * is free and exact for the request being recorded.
     *
     * @return list<string>
     */
    public function includedScripts(): array
    {
        return get_included_files();
    }

    public function isCached(string $path): bool
    {
        return opcache_is_script_cached($path);
    }

    public function compile(string $path): bool
    {
        return (bool) @opcache_compile_file($path);
    }
}

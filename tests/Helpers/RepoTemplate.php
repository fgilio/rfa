<?php

namespace Tests\Helpers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

/**
 * Per-process cache for an empty `git init`'d directory. Lives outside
 * `InteractsWithTestRepositories` because trait-level `static` is bound to
 * each consuming class — using the trait directly would re-init once per
 * test class instead of once per worker process.
 */
final class RepoTemplate
{
    private static ?string $path = null;

    /** @param  callable(string $command, string $errorPrefix): string  $execOrThrow */
    public static function path(callable $execOrThrow): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        $base = sys_get_temp_dir().'/rfa_repo_tpl_'.getmypid().'_'.bin2hex(random_bytes(4));
        File::makeDirectory($base, 0755, true);

        try {
            $execOrThrow(
                'git init -b main -q '.escapeshellarg($base),
                'Failed to initialize git repo template',
            );
        } catch (\Throwable $e) {
            File::deleteDirectory($base);
            throw $e;
        }

        // Resolve the Filesystem now; the container is gone by shutdown.
        $filesystem = new Filesystem;
        register_shutdown_function(static function () use ($filesystem, $base): void {
            $filesystem->deleteDirectory($base);
        });

        return self::$path = $base.'/.git';
    }
}

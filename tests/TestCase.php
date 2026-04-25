<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Livewire\Compiler\CacheManager;
use Livewire\Compiler\Compiler;

abstract class TestCase extends BaseTestCase
{
    use WithCachedConfig;
    use WithCachedRoutes;

    private const ENV_VIEW_COMPILED = 'VIEW_COMPILED_PATH';

    /** False = unresolved, null = no token, string = path. */
    private static string|false|null $isolatedLivewireCachePath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $path = $this->resolveIsolatedLivewireCachePath();

        if ($path !== null) {
            $this->app->instance('livewire.compiler', new Compiler(new CacheManager($path)));
        }
    }

    /**
     * Livewire's compiler singleton points at a fixed
     * `storage/framework/views/livewire` path; under parallel testing that
     * shared dir races (one worker rewriting a file while another reads it).
     * Per-token paths give each worker its own cache.
     */
    private function resolveIsolatedLivewireCachePath(): ?string
    {
        if (self::$isolatedLivewireCachePath !== false) {
            return self::$isolatedLivewireCachePath;
        }

        $token = ParallelTesting::token();

        if ($token === false) {
            return self::$isolatedLivewireCachePath = null;
        }

        $path = storage_path('framework/views/livewire_test_'.$token);
        File::ensureDirectoryExists($path);

        // Child PHP processes spawned mid-test must inherit the per-worker
        // compiled blade dir, otherwise they fall back to the shared default
        // and race other workers.
        $compiled = $this->app['config']->get('view.compiled');

        if (is_string($compiled) && $compiled !== '') {
            File::ensureDirectoryExists($compiled);
            putenv(self::ENV_VIEW_COMPILED.'='.$compiled);
        }

        return self::$isolatedLivewireCachePath = $path;
    }
}

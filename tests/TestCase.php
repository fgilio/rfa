<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Livewire\Compiler\CacheManager;
use Livewire\Compiler\Compiler;

abstract class TestCase extends BaseTestCase
{
    // Memoize config + routes across the worker so each test reuses the
    // already-built application state instead of rebuilding it from scratch.
    // Added in Laravel 12.38; reports 10–40% faster boot in route-/config-heavy
    // suites.
    use WithCachedConfig;
    use WithCachedRoutes;

    /** Per-process cached path; null when not running under parallel testing. */
    private static ?string $isolatedLivewireCachePath = null;

    private static bool $isolatedPathsResolved = false;

    /**
     * Laravel's parallel-testing trait isolates blade compiled views
     * (storage/framework/views/test_<token>) and SQLite (per-process :memory:).
     * Livewire's compiler singleton, though, points at a fixed
     * storage/framework/views/livewire path — which causes flaky races across
     * workers (one worker deletes/rewrites a file while another reads it).
     *
     * Re-bind the compiler to a per-process directory whenever a token is
     * present so each worker reads/writes its own livewire cache.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $path = $this->resolveIsolatedLivewireCachePath();

        if ($path !== null) {
            $this->app->instance('livewire.compiler', new Compiler(new CacheManager($path)));
        }
    }

    private function resolveIsolatedLivewireCachePath(): ?string
    {
        if (self::$isolatedPathsResolved) {
            return self::$isolatedLivewireCachePath;
        }

        self::$isolatedPathsResolved = true;

        $token = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? getenv('TEST_TOKEN');

        if ($token === false || $token === null || $token === '') {
            return self::$isolatedLivewireCachePath = null;
        }

        $path = storage_path('framework/views/livewire_test_'.$token);

        if (! is_dir($path)) {
            @mkdir($path, 0o755, true);
        }

        // Propagate the per-worker compiled blade path to any child PHP
        // processes spawned during the test (e.g. `BenchmarkPerformanceCommand`
        // forks `php artisan rfa:benchmark-perf --child`). Without this they
        // share `storage/framework/views/` with every other worker and race.
        $compiled = $this->app['config']->get('view.compiled');

        if (is_string($compiled) && $compiled !== '') {
            if (! is_dir($compiled)) {
                @mkdir($compiled, 0o755, true);
            }

            putenv('VIEW_COMPILED_PATH='.$compiled);
            $_ENV['VIEW_COMPILED_PATH'] = $compiled;
            $_SERVER['VIEW_COMPILED_PATH'] = $compiled;
        }

        return self::$isolatedLivewireCachePath = $path;
    }
}

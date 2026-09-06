<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The cache paths are read from the environment at write time, the way the
 * Electron main process points them at its staging directory, so the test
 * never touches the checkout's bootstrap/cache.
 */
beforeEach(function () {
    $this->stagingDir = sys_get_temp_dir().'/rfa_test_optimize_'.getmypid().'_'.uniqid('', true);
    File::ensureDirectoryExists($this->stagingDir);

    $this->cacheEnv = [
        'APP_CONFIG_CACHE' => $this->stagingDir.'/config.php',
        'APP_ROUTES_CACHE' => $this->stagingDir.'/routes-v7.php',
        'APP_EVENTS_CACHE' => $this->stagingDir.'/events.php',
    ];

    foreach ($this->cacheEnv as $key => $path) {
        $_SERVER[$key] = $path;
        $_ENV[$key] = $path;
    }
});

afterEach(function () {
    foreach (array_keys($this->cacheEnv) as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
    }

    File::deleteDirectory($this->stagingDir);
});

test('rfa:optimize writes the config, route, and event caches where the environment points and compiles the views', function () {
    $compiledDir = (string) config('view.compiled');
    $sentinel = $compiledDir.'/rfa-test-live-request.php';
    File::ensureDirectoryExists($compiledDir);
    File::put($sentinel, '<?php return "in use";');

    try {
        $this->artisan('rfa:optimize')
            ->expectsOutputToContain('Compiled')
            ->assertSuccessful();

        expect(File::exists($this->cacheEnv['APP_CONFIG_CACHE']))->toBeTrue()
            ->and(File::exists($this->cacheEnv['APP_ROUTES_CACHE']))->toBeTrue()
            ->and(File::exists($this->cacheEnv['APP_EVENTS_CACHE']))->toBeTrue()
            ->and(File::get($sentinel))->toBe('<?php return "in use";')
            ->and(File::exists(app('blade.compiler')->getCompiledPath(resource_path('views/components/empty-state.blade.php'))))->toBeTrue();
    } finally {
        File::delete($sentinel);
    }
});

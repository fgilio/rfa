<?php

use App\Actions\RehydrateNativeRuntimeConfigAction;
use Tests\TestCase;

uses(TestCase::class);

/**
 * configurationIsCached() returns the bound `config_loaded_from_cache` flag, so
 * bind it directly to model a packaged (cached) vs dev (uncached) runtime
 * without touching the shared bootstrap/cache that parallel workers depend on.
 */
function setConfigurationCached(bool $cached): void
{
    app()->instance('config_loaded_from_cache', $cached);
}

afterEach(function () {
    foreach (['NATIVEPHP_API_URL', 'NATIVEPHP_SECRET'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

test('rehydrates the per-launch api url and secret from the live environment when config is cached', function () {
    setConfigurationCached(true);

    // Simulate a stale version-cached config and the fresh per-launch values
    // Electron injects into the process environment.
    config([
        'nativephp-internal.api_url' => 'http://localhost:4000/api/',
        'nativephp-internal.secret' => 'stale-secret',
    ]);
    $_SERVER['NATIVEPHP_API_URL'] = 'http://localhost:52111/api/';
    $_SERVER['NATIVEPHP_SECRET'] = 'fresh-launch-secret';

    (new RehydrateNativeRuntimeConfigAction)->handle();

    expect(config('nativephp-internal.api_url'))->toBe('http://localhost:52111/api/')
        ->and(config('nativephp-internal.secret'))->toBe('fresh-launch-secret');
});

test('is a no-op when the configuration is not cached', function () {
    setConfigurationCached(false);

    // A dev / browser / test runtime reads these straight from env() in the
    // config file, so they are already live and must be left untouched.
    config([
        'nativephp-internal.api_url' => 'http://localhost:4000/api/',
        'nativephp-internal.secret' => 'config-file-secret',
    ]);
    $_SERVER['NATIVEPHP_API_URL'] = 'http://localhost:52111/api/';
    $_SERVER['NATIVEPHP_SECRET'] = 'fresh-launch-secret';

    (new RehydrateNativeRuntimeConfigAction)->handle();

    expect(config('nativephp-internal.api_url'))->toBe('http://localhost:4000/api/')
        ->and(config('nativephp-internal.secret'))->toBe('config-file-secret');
});

test('keeps the cached value when the environment variable is absent', function () {
    setConfigurationCached(true);

    config([
        'nativephp-internal.api_url' => 'http://localhost:4000/api/',
        'nativephp-internal.secret' => 'cached-secret',
    ]);
    // Only the port is injected this launch; the secret env var is missing.
    $_SERVER['NATIVEPHP_API_URL'] = 'http://localhost:52111/api/';

    (new RehydrateNativeRuntimeConfigAction)->handle();

    expect(config('nativephp-internal.api_url'))->toBe('http://localhost:52111/api/')
        // Never blanked: the existing value survives a missing env var.
        ->and(config('nativephp-internal.secret'))->toBe('cached-secret');
});

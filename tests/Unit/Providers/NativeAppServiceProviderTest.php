<?php

use App\Console\Benchmark\BenchmarkIsolation;
use App\Providers\NativeAppServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['app.debug' => true]);

    Cache::forget('native-update-state');

    $provider = new ReflectionClass(NativeAppServiceProvider::class);

    collect([
        'compiledViewsClearedForDev',
        'nativeDevelopmentDatabaseChecked',
    ])->each(function (string $property) use ($provider): void {
        $provider->getProperty($property)->setValue(false);
    });
});

test('dev compiled view cleanup does not clear persisted updater state', function () {
    Cache::put('native-update-state', [
        'status' => 'checking',
        'startedAt' => now()->timestamp,
        'simulateTerminalState' => true,
    ], now()->addMinutes(2));

    $provider = new ReflectionClass(NativeAppServiceProvider::class);
    $clearCompiledViewsForDev = $provider->getMethod('clearCompiledViewsForDev');
    $clearCompiledViewsForDev->setAccessible(true);

    $serviceProvider = new NativeAppServiceProvider;

    $clearCompiledViewsForDev->invoke($serviceProvider);

    expect(Cache::get('native-update-state'))->toMatchArray([
        'status' => 'checking',
        'simulateTerminalState' => true,
    ]);

    $provider->getProperty('compiledViewsClearedForDev')->setValue(false);

    $clearCompiledViewsForDev->invoke($serviceProvider);

    expect(Cache::get('native-update-state'))->toMatchArray([
        'status' => 'checking',
        'simulateTerminalState' => true,
    ]);
});

test('dev compiled view cleanup skips deletion in testing environment', function () {
    $viewsPath = storage_path('framework/views');
    File::ensureDirectoryExists($viewsPath);
    $sentinel = $viewsPath.'/sentinel-'.bin2hex(random_bytes(4)).'.php';
    File::put($sentinel, '<?php // sentinel');

    $provider = new ReflectionClass(NativeAppServiceProvider::class);
    $clearCompiledViewsForDev = $provider->getMethod('clearCompiledViewsForDev');
    $clearCompiledViewsForDev->setAccessible(true);

    $clearCompiledViewsForDev->invoke(new NativeAppServiceProvider);

    expect(File::exists($sentinel))->toBeTrue();
    expect($provider->getProperty('compiledViewsClearedForDev')->getValue())->toBeFalse();

    File::delete($sentinel);
});

test('dev compiled view cleanup skips deletion when benchmark isolation is active', function () {
    $viewsPath = storage_path('framework/views');
    File::ensureDirectoryExists($viewsPath);
    $sentinel = $viewsPath.'/sentinel-'.bin2hex(random_bytes(4)).'.php';
    File::put($sentinel, '<?php // sentinel');

    $originalEnvVar = getenv(BenchmarkIsolation::ENV_ENABLED);
    $originalEnvironment = app()->environment();

    try {
        putenv(BenchmarkIsolation::ENV_ENABLED.'=1');
        app()->detectEnvironment(fn () => 'local');

        $provider = new ReflectionClass(NativeAppServiceProvider::class);
        $clearCompiledViewsForDev = $provider->getMethod('clearCompiledViewsForDev');
        $clearCompiledViewsForDev->setAccessible(true);

        $clearCompiledViewsForDev->invoke(new NativeAppServiceProvider);

        expect(File::exists($sentinel))->toBeTrue();
        expect($provider->getProperty('compiledViewsClearedForDev')->getValue())->toBeFalse();
    } finally {
        File::delete($sentinel);

        if ($originalEnvVar === false) {
            putenv(BenchmarkIsolation::ENV_ENABLED);
        } else {
            putenv(BenchmarkIsolation::ENV_ENABLED.'='.$originalEnvVar);
        }

        app()->detectEnvironment(fn () => $originalEnvironment);
    }
});

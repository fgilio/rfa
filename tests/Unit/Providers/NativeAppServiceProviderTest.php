<?php

use App\Providers\NativeAppServiceProvider;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class);

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

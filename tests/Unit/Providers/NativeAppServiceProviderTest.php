<?php

use App\Console\Benchmark\BenchmarkIsolation;
use App\Providers\NativeAppServiceProvider;
use App\Support\Shortcuts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;
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

function updateNotAvailableListener(): Closure
{
    Event::forget(UpdateNotAvailable::class);

    $provider = new ReflectionClass(NativeAppServiceProvider::class);
    $registerNativeEventListeners = $provider->getMethod('registerNativeEventListeners');
    $registerNativeEventListeners->invoke(new NativeAppServiceProvider);

    return collect(Event::getFacadeRoot()->getListeners(UpdateNotAvailable::class))->sole();
}

function updateNotAvailableEvent(): UpdateNotAvailable
{
    return new UpdateNotAvailable(
        version: '1.2.3',
        files: [],
        releaseDate: '2026-08-13',
    );
}

test('no-update listener emits a canonical terminal event', function () {
    Http::fake();
    Log::spy();

    $listener = updateNotAvailableListener();
    $event = updateNotAvailableEvent();

    $listener(UpdateNotAvailable::class, [$event]);

    Log::shouldHaveReceived('info')->once()->with('updater.current');
    expect(Cache::get('native-update-state'))->toMatchArray(['status' => 'up-to-date'])
        ->and(Context::get('rfa.update_version'))->toBe('1.2.3')
        ->and(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.duration_ms'))->toBeInt();
});

test('no-update listener emits an error outcome when handling fails', function () {
    Log::spy();
    Cache::shouldReceive('put')
        ->once()
        ->andThrow(new RuntimeException('Cache write failed.'));

    $listener = updateNotAvailableListener();
    $event = updateNotAvailableEvent();

    expect(fn () => $listener(UpdateNotAvailable::class, [$event]))
        ->toThrow(RuntimeException::class, 'Cache write failed.');

    Log::shouldHaveReceived('info')->once()->with('updater.current');
    expect(Context::get('rfa.update_version'))->toBe('1.2.3')
        ->and(Context::get('rfa.outcome'))->toBe('error')
        ->and(Context::get('rfa.reason'))->toBe('update_not_available_handling_failed')
        ->and(Context::get('rfa.error_class'))->toBe(RuntimeException::class)
        ->and(Context::get('rfa.duration_ms'))->toBeInt();
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

// -- phpIni / opcache --

test('phpIni enables opcache with a persistent file cache for the bundled PHP', function () {
    $ini = (new NativeAppServiceProvider)->phpIni();

    expect($ini)
        ->toHaveKey('memory_limit', '512M')
        ->toHaveKey('opcache.enable', 1)
        ->toHaveKey('opcache.enable_cli', 1)
        // Keep correctness in dev and across updates: recompile changed files.
        ->toHaveKey('opcache.validate_timestamps', 1)
        ->toHaveKey('opcache.file_cache', storage_path('framework/opcache'));
});

test('phpIni ensures the opcache file-cache directory exists', function () {
    $cacheDir = storage_path('framework/opcache');

    if (is_dir($cacheDir)) {
        File::deleteDirectory($cacheDir);
    }

    (new NativeAppServiceProvider)->phpIni();

    expect(is_dir($cacheDir))->toBeTrue();
});

test('main window does not force a background that can disagree with the persisted appearance', function () {
    $source = (string) file_get_contents((new ReflectionClass(NativeAppServiceProvider::class))->getFileName());

    expect($source)->not->toContain('->backgroundColor(');
});

// -- Menu structure --

test('the View submenu declares the review menu items with their accelerators', function () {
    // The hotkeys live in the menu builder DSL, which is wired through the
    // native bridge. Read the source directly so the assertion stays fast
    // and decoupled from the bridge. The accelerators are sourced from the
    // shortcuts catalog so the menu and the cheat sheet can't drift.
    $source = file_get_contents((new ReflectionClass(NativeAppServiceProvider::class))->getFileName());

    expect($source)
        ->toContain("Menu::label('Review Code')")
        ->toContain("->id('review-code')")
        ->toContain("->hotkey(Shortcuts::accelerator('app.review-code'))")
        ->toContain("Menu::label('Review Agents instructions')")
        ->toContain("->id('show-context')")
        ->toContain("->hotkey(Shortcuts::accelerator('app.context-files'))");

    expect(Shortcuts::accelerator('app.review-code'))->toBe('CmdOrCtrl+Shift+C');
    expect(Shortcuts::accelerator('app.context-files'))->toBe('CmdOrCtrl+Shift+K');
});

test('the View submenu declares the keyboard-shortcuts menu item', function () {
    // No hotkey: the `?` keymap shortcut already opens the cheat sheet, so the
    // menu item is a click-only affordance that broadcasts ShowShortcutsRequested.
    $source = file_get_contents((new ReflectionClass(NativeAppServiceProvider::class))->getFileName());

    expect($source)
        ->toContain("Menu::label('Keyboard Shortcuts')")
        ->toContain("->id('show-shortcuts')");
});

test('the View submenu declares the sidebar toggle menu item with no accelerator', function () {
    // No hotkey either: hyper+S is a renderer keymap binding, so an Electron
    // accelerator here would swallow the keystroke before the page sees it.
    // The item is a click-only affordance that broadcasts ToggleSidebarRequested.
    $source = (string) file_get_contents((new ReflectionClass(NativeAppServiceProvider::class))->getFileName());

    $start = strpos($source, "Menu::label('Toggle Sidebar')");

    expect($start)->not->toBeFalse();

    // Slice out this item's own builder chain — everything up to the next
    // Menu:: call — so a ->hotkey() anywhere in it is caught, whether it lands
    // before or after ->id(). Asserting on the whole file would let one slip in.
    $chain = substr($source, (int) $start, (int) strpos($source, 'Menu::', (int) $start + 1) - (int) $start);

    expect($chain)
        ->toContain("->id('toggle-sidebar')")
        ->not->toContain('hotkey');

    expect(Shortcuts::accelerator('sidebar.toggle'))->toBeNull();
});

test('dev compiled view cleanup skips deletion when the configuration is cached (packaged build)', function () {
    // The packaged app runs `php artisan optimize` at launch, so the config is
    // cached and Blade is already compiled. Re-clearing it on every request is
    // what made cold start and navigation sluggish. Point APP_CONFIG_CACHE at a
    // real temp file so app()->configurationIsCached() is true without touching
    // the shared bootstrap/cache (which would corrupt other parallel workers).
    $viewsPath = storage_path('framework/views');
    File::ensureDirectoryExists($viewsPath);
    $sentinel = $viewsPath.'/sentinel-'.bin2hex(random_bytes(4)).'.php';
    File::put($sentinel, '<?php // sentinel');

    $cachedConfig = sys_get_temp_dir().'/rfa_test_cfgcache_'.getmypid().'_'.uniqid('', true).'.php';
    File::put($cachedConfig, '<?php return [];');

    $originalEnvironment = app()->environment();

    putenv('APP_CONFIG_CACHE='.$cachedConfig);
    $_ENV['APP_CONFIG_CACHE'] = $cachedConfig;
    $_SERVER['APP_CONFIG_CACHE'] = $cachedConfig;

    try {
        // Leave the testing environment so the testing/benchmark guards (which
        // sit *after* the cache guard) can't be what stops the deletion.
        app()->detectEnvironment(fn () => 'local');

        expect(app()->configurationIsCached())->toBeTrue();

        $provider = new ReflectionClass(NativeAppServiceProvider::class);
        $clearCompiledViewsForDev = $provider->getMethod('clearCompiledViewsForDev');
        $clearCompiledViewsForDev->setAccessible(true);

        $clearCompiledViewsForDev->invoke(new NativeAppServiceProvider);

        expect(File::exists($sentinel))->toBeTrue();
        expect($provider->getProperty('compiledViewsClearedForDev')->getValue())->toBeFalse();
    } finally {
        File::delete($sentinel);
        File::delete($cachedConfig);

        putenv('APP_CONFIG_CACHE');
        unset($_ENV['APP_CONFIG_CACHE'], $_SERVER['APP_CONFIG_CACHE']);

        app()->detectEnvironment(fn () => $originalEnvironment);
    }
});

test('dev database migration check is skipped when the configuration is cached (packaged build)', function () {
    // NativePHP runs `migrate --force` itself at launch in a packaged build, so
    // this dev-only scan is redundant there. Returning early at the cache guard
    // leaves the static flag untouched (it is only set once the scan proceeds).
    config(['nativephp-internal.running' => true]);

    $cachedConfig = sys_get_temp_dir().'/rfa_test_cfgcache_'.getmypid().'_'.uniqid('', true).'.php';
    File::put($cachedConfig, '<?php return [];');

    putenv('APP_CONFIG_CACHE='.$cachedConfig);
    $_ENV['APP_CONFIG_CACHE'] = $cachedConfig;
    $_SERVER['APP_CONFIG_CACHE'] = $cachedConfig;

    try {
        expect(app()->configurationIsCached())->toBeTrue();

        $provider = new ReflectionClass(NativeAppServiceProvider::class);
        $method = $provider->getMethod('ensureNativeDevelopmentDatabaseIsMigrated');
        $method->setAccessible(true);

        $method->invoke(new NativeAppServiceProvider);

        expect($provider->getProperty('nativeDevelopmentDatabaseChecked')->getValue())->toBeFalse();
    } finally {
        File::delete($cachedConfig);

        putenv('APP_CONFIG_CACHE');
        unset($_ENV['APP_CONFIG_CACHE'], $_SERVER['APP_CONFIG_CACHE']);
    }
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

<?php

use App\Console\Benchmark\BenchmarkIsolation;
use App\Providers\NativeAppServiceProvider;
use App\Support\Shortcuts;
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

// -- Inbox parser --

test('two-line inbox with context mode routes to context-page', function () {
    expect(NativeAppServiceProvider::parseInboxContents("/some/repo\ncontext\n"))
        ->toBe(['path' => '/some/repo', 'route' => 'context-page']);
});

test('single-line legacy inbox file routes to review-page', function () {
    expect(NativeAppServiceProvider::parseInboxContents("/some/repo\n"))
        ->toBe(['path' => '/some/repo', 'route' => 'review-page']);

    expect(NativeAppServiceProvider::parseInboxContents('/some/repo'))
        ->toBe(['path' => '/some/repo', 'route' => 'review-page']);
});

test('two-line inbox with empty second line routes to review-page', function () {
    expect(NativeAppServiceProvider::parseInboxContents("/some/repo\n\n"))
        ->toBe(['path' => '/some/repo', 'route' => 'review-page']);
});

test('two-line inbox with junk second line routes to review-page (fail open)', function () {
    expect(NativeAppServiceProvider::parseInboxContents("/some/repo\nxyz\n"))
        ->toBe(['path' => '/some/repo', 'route' => 'review-page']);
});

test('trailing newline does not drop the mode line', function () {
    // `printf '%s\n%s\n'` always emits a trailing newline. Split-then-trim
    // per line keeps the mode intact even with extra blank lines after it.
    expect(NativeAppServiceProvider::parseInboxContents("/some/repo\ncontext\n\n"))
        ->toBe(['path' => '/some/repo', 'route' => 'context-page']);
});

test('CRLF line endings are handled the same as LF', function () {
    expect(NativeAppServiceProvider::parseInboxContents("/some/repo\r\ncontext\r\n"))
        ->toBe(['path' => '/some/repo', 'route' => 'context-page']);
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

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

// -- Menu structure --

test('the View submenu declares the show-context menu item with the cmd-shift-k accelerator', function () {
    // The hotkey lives in the menu builder DSL, which is wired through the
    // native bridge. Read the source directly so the assertion stays fast
    // and decoupled from the bridge.
    $source = file_get_contents((new ReflectionClass(NativeAppServiceProvider::class))->getFileName());

    expect($source)
        ->toContain("Menu::label('Show Context Files...')")
        ->toContain("->id('show-context')")
        ->toContain("->hotkey('CmdOrCtrl+Shift+K')");
});

test('the Quit menu item is a labeled hotkey, not the native role', function () {
    // Native Menu::quit() compiles to {role: 'quit'} and bypasses both PHP
    // listeners and the renderer broadcast (electron-plugin's helper does
    // not call notifyLaravel for role items). We need MenuItemClicked to
    // fire so the hold-to-quit overlay can intercept the press.
    $source = file_get_contents((new ReflectionClass(NativeAppServiceProvider::class))->getFileName());

    expect($source)
        ->toContain("->id('quit-rfa')")
        ->toContain("->hotkey('CmdOrCtrl+Q')");

    // Strip comments and re-check for the role call so the explanatory
    // comment that mentions Menu::quit() does not pass the assertion.
    $stripped = preg_replace('!//.*?$|/\*.*?\*/!ms', '', $source);

    expect($stripped)->not->toContain('Menu::quit()');
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

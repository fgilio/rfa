<?php

declare(strict_types=1);

use App\Actions\ZoomWindowAction;
use App\Events\HardReloadShortcutPressed;
use App\Events\RefreshShortcutPressed;
use App\Events\ZoomShortcutPressed;
use App\Listeners\HandleZoomShortcutPressed;
use App\Listeners\RegisterNativeGlobalShortcuts;
use App\Listeners\UnregisterNativeGlobalShortcuts;
use App\Providers\NativeAppServiceProvider;
use App\Support\NativeShortcutRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Native\Desktop\Contracts\WindowManager;
use Native\Desktop\Events\Windows\WindowBlurred;
use Native\Desktop\Events\Windows\WindowFocused;
use Native\Desktop\Facades\GlobalShortcut;
use Native\Desktop\Windows\Window;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::forget(ZoomWindowAction::CACHE_KEY);
});

test('native provider registers the app-level shortcut listeners', function () {
    $provider = new ReflectionClass(NativeAppServiceProvider::class);
    $registerNativeEventListeners = $provider->getMethod('registerNativeEventListeners');
    $registerNativeEventListeners->setAccessible(true);

    $registerNativeEventListeners->invoke(new NativeAppServiceProvider);

    Event::fake();

    Event::assertListening(WindowFocused::class, RegisterNativeGlobalShortcuts::class);
    Event::assertListening(WindowBlurred::class, UnregisterNativeGlobalShortcuts::class);
    Event::assertListening(ZoomShortcutPressed::class, HandleZoomShortcutPressed::class);
});

test('main window focus registers native shortcuts', function () {
    $shortcut = GlobalShortcut::fake();

    app(RegisterNativeGlobalShortcuts::class)->handle(new WindowFocused('main'));

    expect($shortcut->keys)->toBe(NativeShortcutRegistry::keys())
        ->and($shortcut->events)->toBe(array_column(NativeShortcutRegistry::all(), 'event'));

    $shortcut->assertRegisteredCount(count(NativeShortcutRegistry::all()));
});

test('other window focus does not register native shortcuts', function () {
    $shortcut = GlobalShortcut::fake();

    app(RegisterNativeGlobalShortcuts::class)->handle(new WindowFocused('secondary'));

    $shortcut->assertRegisteredCount(0);
});

test('main window blur unregisters native shortcuts', function () {
    $shortcut = GlobalShortcut::fake();

    app(UnregisterNativeGlobalShortcuts::class)->handle(new WindowBlurred('main'));

    expect($shortcut->keys)->toBe(NativeShortcutRegistry::keys());
    $shortcut->assertUnregisteredCount(count(NativeShortcutRegistry::keys()));
});

test('other window blur does not unregister native shortcuts', function () {
    $shortcut = GlobalShortcut::fake();

    app(UnregisterNativeGlobalShortcuts::class)->handle(new WindowBlurred('secondary'));

    $shortcut->assertUnregisteredCount(0);
});

test('native registry includes refresh and hard reload shortcuts', function () {
    expect(NativeShortcutRegistry::all())->toContain(
        ['key' => RefreshShortcutPressed::KEY, 'event' => RefreshShortcutPressed::class],
        ['key' => HardReloadShortcutPressed::KEY, 'event' => HardReloadShortcutPressed::class],
    );
});

test('native registry shortcut keys are unique', function () {
    expect(NativeShortcutRegistry::keys())->toBe(array_values(array_unique(NativeShortcutRegistry::keys())));
});

test('zoom in shortcut increases the current window zoom', function () {
    expectZoomFactor(1.1);

    app(HandleZoomShortcutPressed::class)->handle(new ZoomShortcutPressed(ZoomShortcutPressed::ZOOM_IN));

    expect(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(1.1);
});

test('zoom plus shortcut increases the current window zoom', function () {
    expectZoomFactor(1.1);

    app(HandleZoomShortcutPressed::class)->handle(new ZoomShortcutPressed(ZoomShortcutPressed::ZOOM_IN_PLUS));

    expect(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(1.1);
});

test('zoom out shortcut decreases the current window zoom', function () {
    expectZoomFactor(0.9);

    app(HandleZoomShortcutPressed::class)->handle(new ZoomShortcutPressed(ZoomShortcutPressed::ZOOM_OUT));

    expect(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(0.9);
});

test('reset zoom shortcut restores the default window zoom', function () {
    Cache::put(ZoomWindowAction::CACHE_KEY, 1.4, now()->addDay());
    expectZoomFactor(1.0);

    app(HandleZoomShortcutPressed::class)->handle(new ZoomShortcutPressed(ZoomShortcutPressed::RESET));

    expect(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(1.0);
});

test('zoom shortcut listener ignores unexpected shortcut payloads', function () {
    $manager = Mockery::mock(WindowManager::class);
    $manager->shouldNotReceive('get');
    app()->instance(WindowManager::class, $manager);

    app(HandleZoomShortcutPressed::class)->handle(new ZoomShortcutPressed('CommandOrControl+9'));

    expect(Cache::get(ZoomWindowAction::CACHE_KEY))->toBeNull();
});

function expectZoomFactor(float $factor): void
{
    $window = Mockery::mock(Window::class);
    $window->shouldReceive('zoomFactor')->once()->with($factor);

    $manager = Mockery::mock(WindowManager::class);
    $manager->shouldReceive('get')->with('main')->andReturn($window);
    app()->instance(WindowManager::class, $manager);
}

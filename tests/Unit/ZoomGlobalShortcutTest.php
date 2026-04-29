<?php

declare(strict_types=1);

use App\Actions\ZoomWindowAction;
use App\Events\ZoomShortcutPressed;
use App\Listeners\HandleZoomShortcutPressed;
use App\Listeners\RegisterZoomGlobalShortcuts;
use App\Listeners\UnregisterZoomGlobalShortcuts;
use App\Providers\NativeAppServiceProvider;
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

test('native provider registers the zoom shortcut listeners', function () {
    $provider = new ReflectionClass(NativeAppServiceProvider::class);
    $registerNativeEventListeners = $provider->getMethod('registerNativeEventListeners');
    $registerNativeEventListeners->setAccessible(true);

    $registerNativeEventListeners->invoke(new NativeAppServiceProvider);

    Event::fake();

    Event::assertListening(WindowFocused::class, RegisterZoomGlobalShortcuts::class);
    Event::assertListening(WindowBlurred::class, UnregisterZoomGlobalShortcuts::class);
    Event::assertListening(ZoomShortcutPressed::class, HandleZoomShortcutPressed::class);
});

test('main window focus registers zoom keys as global shortcuts', function () {
    $shortcut = GlobalShortcut::fake();

    app(RegisterZoomGlobalShortcuts::class)->handle(new WindowFocused('main'));

    expect($shortcut->keys)->toBe(ZoomShortcutPressed::keys())
        ->and($shortcut->events)->toBe([
            ZoomShortcutPressed::class,
            ZoomShortcutPressed::class,
            ZoomShortcutPressed::class,
            ZoomShortcutPressed::class,
        ]);

    $shortcut->assertRegisteredCount(4);
});

test('other window focus does not register zoom shortcuts', function () {
    $shortcut = GlobalShortcut::fake();

    app(RegisterZoomGlobalShortcuts::class)->handle(new WindowFocused('secondary'));

    $shortcut->assertRegisteredCount(0);
});

test('main window blur unregisters zoom keys as global shortcuts', function () {
    $shortcut = GlobalShortcut::fake();

    app(UnregisterZoomGlobalShortcuts::class)->handle(new WindowBlurred('main'));

    expect($shortcut->keys)->toBe(ZoomShortcutPressed::keys());
    $shortcut->assertUnregisteredCount(4);
});

test('other window blur does not unregister zoom shortcuts', function () {
    $shortcut = GlobalShortcut::fake();

    app(UnregisterZoomGlobalShortcuts::class)->handle(new WindowBlurred('secondary'));

    $shortcut->assertUnregisteredCount(0);
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

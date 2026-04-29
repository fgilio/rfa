<?php

declare(strict_types=1);

use App\Actions\ZoomWindowAction;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Contracts\WindowManager;
use Native\Desktop\Windows\Window;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::forget(ZoomWindowAction::CACHE_KEY);

    $this->window = Mockery::mock(Window::class);
    $this->window->shouldReceive('zoomFactor')->byDefault();

    $manager = Mockery::mock(WindowManager::class);
    $manager->shouldReceive('get')->with('main')->andReturn($this->window);
    app()->instance(WindowManager::class, $manager);

    $this->action = app(ZoomWindowAction::class);
});

test('current returns 1.0 when no cached factor', function () {
    expect($this->action->current())->toBe(1.0);
});

test('handle("in") bumps the factor by one step and persists it', function () {
    $this->window->shouldReceive('zoomFactor')->once()->with(1.1);

    $next = $this->action->handle('in');

    expect($next)->toBe(1.1)
        ->and(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(1.1);
});

test('handle("out") drops the factor by one step and persists it', function () {
    $this->window->shouldReceive('zoomFactor')->once()->with(0.9);

    $next = $this->action->handle('out');

    expect($next)->toBe(0.9)
        ->and(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(0.9);
});

test('handle("reset") restores the default factor regardless of current value', function () {
    Cache::put(ZoomWindowAction::CACHE_KEY, 2.4, now()->addDay());
    $this->window->shouldReceive('zoomFactor')->once()->with(1.0);

    expect($this->action->handle('reset'))->toBe(1.0)
        ->and(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(1.0);
});

test('handle("out") clamps to MIN floor', function () {
    Cache::put(ZoomWindowAction::CACHE_KEY, ZoomWindowAction::MIN, now()->addDay());
    $this->window->shouldReceive('zoomFactor')->once()->with(ZoomWindowAction::MIN);

    expect($this->action->handle('out'))->toBe(ZoomWindowAction::MIN)
        ->and(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(ZoomWindowAction::MIN);
});

test('handle("in") clamps to MAX ceiling', function () {
    Cache::put(ZoomWindowAction::CACHE_KEY, ZoomWindowAction::MAX, now()->addDay());
    $this->window->shouldReceive('zoomFactor')->once()->with(ZoomWindowAction::MAX);

    expect($this->action->handle('in'))->toBe(ZoomWindowAction::MAX)
        ->and(Cache::get(ZoomWindowAction::CACHE_KEY))->toBe(ZoomWindowAction::MAX);
});

test('rounds floating-point drift so cache stays on a clean grid', function () {
    Cache::put(ZoomWindowAction::CACHE_KEY, 1.1, now()->addDay());
    $this->window->shouldReceive('zoomFactor')->once()->with(1.2);

    // 1.1 + 0.1 in float = 1.2000000000000002; round(_, 2) collapses it.
    expect($this->action->handle('in'))->toBe(1.2);
});

test('rejects unknown directions with a clear error', function () {
    expect(fn () => $this->action->handle('sideways'))
        ->toThrow(InvalidArgumentException::class, 'Unknown zoom direction: sideways');
});

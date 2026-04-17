<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('returns no-projects when no cache and no projects exist', function () {
    $result = app(ResolveStartupRouteAction::class)->handle();

    expect($result)->toBe(['name' => 'no-projects', 'params' => []]);
});

test('returns review-page when cached slug exists in DB', function () {
    Project::factory()->create(['slug' => 'my-project']);

    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'my-project');

    $result = app(ResolveStartupRouteAction::class)->handle();

    expect($result)->toBe(['name' => 'review-page', 'params' => ['slug' => 'my-project']]);
});

test('returns most-recent project and forgets stale slug when cached project was deleted', function () {
    Project::factory()->create(['slug' => 'surviving-project']);

    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'deleted-project');

    $result = app(ResolveStartupRouteAction::class)->handle();

    expect($result)->toBe(['name' => 'review-page', 'params' => ['slug' => 'surviving-project']]);
    expect(Cache::get(ResolveStartupRouteAction::CACHE_KEY))->toBeNull();
});

test('returns no-projects and forgets stale slug when cache stale and no projects exist', function () {
    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'deleted-project');

    $result = app(ResolveStartupRouteAction::class)->handle();

    expect($result)->toBe(['name' => 'no-projects', 'params' => []]);
    expect(Cache::get(ResolveStartupRouteAction::CACHE_KEY))->toBeNull();
});

test('returns most-recent project when no cache and projects exist', function () {
    Project::factory()->create(['slug' => 'older', 'updated_at' => now()->subHour()]);
    Project::factory()->create(['slug' => 'newer', 'updated_at' => now()]);

    $result = app(ResolveStartupRouteAction::class)->handle();

    expect($result)->toBe(['name' => 'review-page', 'params' => ['slug' => 'newer']]);
});

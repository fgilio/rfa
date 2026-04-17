<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('returns no-projects when no cache and no projects exist', function () {
    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('no-projects'));
});

test('returns review-page when cached slug exists in DB', function () {
    Project::factory()->create(['slug' => 'my-project']);

    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'my-project');

    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('review-page', ['slug' => 'my-project']));
});

test('returns most-recent project and forgets stale slug when cached project was deleted', function () {
    Project::factory()->create(['slug' => 'surviving-project']);

    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'deleted-project');

    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('review-page', ['slug' => 'surviving-project']));
    expect(Cache::get(ResolveStartupRouteAction::CACHE_KEY))->toBeNull();
});

test('returns no-projects and forgets stale slug when cache stale and no projects exist', function () {
    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'deleted-project');

    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('no-projects'));
    expect(Cache::get(ResolveStartupRouteAction::CACHE_KEY))->toBeNull();
});

test('returns most-recent project when no cache and projects exist', function () {
    Project::factory()->create(['slug' => 'older', 'updated_at' => now()->subHour()]);
    Project::factory()->create(['slug' => 'newer', 'updated_at' => now()]);

    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('review-page', ['slug' => 'newer']));
});

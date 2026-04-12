<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('returns dashboard when no cached slug', function () {
    $result = app(ResolveStartupRouteAction::class)->handle();

    expect($result)->toBe(['name' => 'dashboard', 'params' => []]);
});

test('returns review-page when cached slug exists in DB', function () {
    $project = Project::factory()->create(['slug' => 'my-project']);

    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'my-project');

    $result = app(ResolveStartupRouteAction::class)->handle();

    expect($result)->toBe(['name' => 'review-page', 'params' => ['slug' => 'my-project']]);
});

test('returns dashboard and forgets stale slug when project was deleted', function () {
    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'deleted-project');

    $result = app(ResolveStartupRouteAction::class)->handle();

    expect($result)->toBe(['name' => 'dashboard', 'params' => []]);
    expect(Cache::get(ResolveStartupRouteAction::CACHE_KEY))->toBeNull();
});

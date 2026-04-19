<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('returns no-projects when no cache and no projects exist', function () {
    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('no-projects'));
});

test('returns review-page when cached slug exists in DB', function () {
    Project::factory()->create(['slug' => 'my-project']);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('my-project');

    expect($action->handle())
        ->toBe(route('review-page', ['slug' => 'my-project']));
});

test('returns most-recent project and forgets stale slug when cached project was deleted', function () {
    Project::factory()->create(['slug' => 'surviving-project']);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('deleted-project');

    expect($action->handle())
        ->toBe(route('review-page', ['slug' => 'surviving-project']));
    expect($action->lastOpenedSlug())->toBeNull();
});

test('returns no-projects and forgets stale slug when cache stale and no projects exist', function () {
    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('deleted-project');

    expect($action->handle())
        ->toBe(route('no-projects'));
    expect($action->lastOpenedSlug())->toBeNull();
});

test('returns most-recent project when no cache and projects exist', function () {
    Project::factory()->create(['slug' => 'older', 'updated_at' => now()->subHour()]);
    Project::factory()->create(['slug' => 'newer', 'updated_at' => now()]);

    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('review-page', ['slug' => 'newer']));
});

test('rememberLastOpened is a no-op when slug already cached', function () {
    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('foo');
    $action->rememberLastOpened('foo');

    expect($action->lastOpenedSlug())->toBe('foo');
});

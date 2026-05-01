<?php

use App\Actions\ResolveStartupRouteAction;
use App\Enums\LastViewMode;
use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('returns select-repo when no cache and no projects exist', function () {
    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('select-repo'));
});

test('returns review-page when cached slug exists in DB', function () {
    Project::factory()->create(['slug' => 'my-project']);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('my-project');

    expect($action->handle())
        ->toBe(route('review-page', ['slug' => 'my-project']));
});

test('returns select-repo and forgets stale slug when cached project was deleted', function () {
    Project::factory()->create(['slug' => 'surviving-project']);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('deleted-project');

    expect($action->handle())->toBe(route('select-repo'));
    expect($action->lastOpenedSlug())->toBeNull();
});

test('returns select-repo and forgets stale slug when cache stale and no projects exist', function () {
    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('deleted-project');

    expect($action->handle())->toBe(route('select-repo'));
    expect($action->lastOpenedSlug())->toBeNull();
});

test('returns select-repo when no cache and projects exist', function () {
    Project::factory()->create(['slug' => 'older', 'updated_at' => now()->subHour()]);
    Project::factory()->create(['slug' => 'newer', 'updated_at' => now()]);

    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('select-repo'));
});

test('rememberLastOpened is a no-op when slug already cached', function () {
    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('foo');
    $action->rememberLastOpened('foo');

    expect($action->lastOpenedSlug())->toBe('foo');
});

test('handle restores the saved Context mode for the last-opened project', function () {
    $project = Project::factory()->create(['slug' => 'has-context']);
    ReviewSession::create([
        'project_id' => $project->id,
        'repo_path' => $project->path,
        'last_view_mode' => LastViewMode::Context,
    ]);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('has-context');

    expect($action->handle())
        ->toBe(route('context-page', ['slug' => 'has-context']));
});

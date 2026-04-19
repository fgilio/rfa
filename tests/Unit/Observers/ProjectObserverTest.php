<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('deleting the last-opened project clears the cached slug', function () {
    $project = Project::factory()->create(['slug' => 'cached-project']);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('cached-project');

    $project->delete();

    expect($action->lastOpenedSlug())->toBeNull();
});

test('deleting a different project preserves the cached slug', function () {
    Project::factory()->create(['slug' => 'project-a']);
    $projectB = Project::factory()->create(['slug' => 'project-b']);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('project-a');

    $projectB->delete();

    expect($action->lastOpenedSlug())->toBe('project-a');
});

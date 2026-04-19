<?php

use App\Actions\RemoveProjectAction;
use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('deletes project by ID', function () {
    $project = Project::factory()->create(['slug' => 'test-project']);

    $result = app(RemoveProjectAction::class)->handle($project->id);

    expect(Project::find($project->id))->toBeNull();
    expect($result)->toBeNull();
});

test('no-op when project does not exist', function () {
    $result = app(RemoveProjectAction::class)->handle(9999);

    expect($result)->toBeNull();
});

test('cascading delete removes associated review session', function () {
    $project = Project::factory()->create(['slug' => 'test-project']);

    $session = ReviewSession::create([
        'project_id' => $project->id,
        'repo_path' => $project->path,
    ]);

    app(RemoveProjectAction::class)->handle($project->id);

    expect(Project::find($project->id))->toBeNull();
    expect(ReviewSession::find($session->id))->toBeNull();
});

test('returns next route when removing the last-opened project leaves another behind', function () {
    $current = Project::factory()->create(['slug' => 'current', 'updated_at' => now()->subHour()]);
    Project::factory()->create(['slug' => 'surviving', 'updated_at' => now()]);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('current');

    $result = app(RemoveProjectAction::class)->handle($current->id);

    expect($result)->toBe(route('review-page', ['slug' => 'surviving']));
});

test('returns no-projects route when removing the only project', function () {
    $only = Project::factory()->create(['slug' => 'only']);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('only');

    $result = app(RemoveProjectAction::class)->handle($only->id);

    expect($result)->toBe(route('no-projects'));
});

test('returns null when removing a non-current project', function () {
    Project::factory()->create(['slug' => 'project-a']);
    $projectB = Project::factory()->create(['slug' => 'project-b']);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('project-a');

    $result = app(RemoveProjectAction::class)->handle($projectB->id);

    expect($result)->toBeNull();
});

<?php

use App\Actions\RemoveProjectAction;
use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('deletes project by ID', function () {
    $project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test Project',
        'path' => '/tmp/test',
        'git_common_dir' => '/tmp/test/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    app(RemoveProjectAction::class)->handle($project->id);

    expect(Project::find($project->id))->toBeNull();
});

test('no-op when project does not exist', function () {
    app(RemoveProjectAction::class)->handle(9999);

    expect(true)->toBeTrue();
});

test('cascading delete removes associated review session', function () {
    $project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test Project',
        'path' => '/tmp/test',
        'git_common_dir' => '/tmp/test/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    $session = ReviewSession::create([
        'project_id' => $project->id,
        'repo_path' => '/tmp/test',
    ]);

    app(RemoveProjectAction::class)->handle($project->id);

    expect(Project::find($project->id))->toBeNull();
    expect(ReviewSession::find($session->id))->toBeNull();
});

test('clears last-opened cache when removing that project', function () {
    $project = Project::factory()->create(['slug' => 'cached-project']);

    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'cached-project');

    app(RemoveProjectAction::class)->handle($project->id);

    expect(Cache::get(ResolveStartupRouteAction::CACHE_KEY))->toBeNull();
});

test('preserves last-opened cache when removing a different project', function () {
    $projectA = Project::factory()->create(['slug' => 'project-a']);
    $projectB = Project::factory()->create(['slug' => 'project-b']);

    Cache::forever(ResolveStartupRouteAction::CACHE_KEY, 'project-a');

    app(RemoveProjectAction::class)->handle($projectB->id);

    expect(Cache::get(ResolveStartupRouteAction::CACHE_KEY))->toBe('project-a');
});

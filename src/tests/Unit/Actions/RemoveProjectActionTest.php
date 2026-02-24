<?php

use App\Actions\RemoveProjectAction;
use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

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

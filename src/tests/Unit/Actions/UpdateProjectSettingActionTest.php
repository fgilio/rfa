<?php

use App\Actions\UpdateProjectSettingAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('updates single attribute on project', function () {
    $project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test Project',
        'path' => '/tmp/test',
        'git_common_dir' => '/tmp/test/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    app(UpdateProjectSettingAction::class)->handle($project->id, ['branch' => 'develop']);

    expect($project->fresh()->branch)->toBe('develop');
});

test('updates multiple attributes at once', function () {
    $project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test Project',
        'path' => '/tmp/test',
        'git_common_dir' => '/tmp/test/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'respect_global_gitignore' => false,
    ]);

    app(UpdateProjectSettingAction::class)->handle($project->id, [
        'branch' => 'feature-x',
        'respect_global_gitignore' => true,
    ]);

    $fresh = $project->fresh();

    expect($fresh->branch)->toBe('feature-x')
        ->and($fresh->respect_global_gitignore)->toBeTrue();
});

test('silently handles non-existent project id', function () {
    app(UpdateProjectSettingAction::class)->handle(9999, ['branch' => 'develop']);

    expect(true)->toBeTrue();
});

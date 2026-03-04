<?php

use App\Actions\BackfillGlobalGitignoreAction;
use App\Models\Project;
use App\Services\GitMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('returns null when git has no global excludes', function () {
    $project = Project::create([
        'slug' => 'test',
        'name' => 'Test',
        'path' => '/tmp/repo',
        'git_common_dir' => '/tmp/repo/.git',
        'branch' => 'main',
    ]);

    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('resolveGlobalExcludesFile')->once()->andReturn(null);

    $action = new BackfillGlobalGitignoreAction($mock);
    $result = $action->handle($project->id, '/tmp/repo');

    expect($result)->toBeNull();
    expect($project->fresh()->global_gitignore_path)->toBeNull();
});

test('resolves and persists path when found', function () {
    $project = Project::create([
        'slug' => 'test',
        'name' => 'Test',
        'path' => '/tmp/repo',
        'git_common_dir' => '/tmp/repo/.git',
        'branch' => 'main',
    ]);

    $resolvedPath = '/home/user/.gitignore_global';

    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('resolveGlobalExcludesFile')->once()->andReturn($resolvedPath);

    $action = new BackfillGlobalGitignoreAction($mock);
    $result = $action->handle($project->id, '/tmp/repo');

    expect($result)->toBe($resolvedPath);
    expect($project->fresh()->global_gitignore_path)->toBe($resolvedPath);
});

test('does not update DB when resolved is null', function () {
    $project = Project::create([
        'slug' => 'test',
        'name' => 'Test',
        'path' => '/tmp/repo',
        'git_common_dir' => '/tmp/repo/.git',
        'branch' => 'main',
        'global_gitignore_path' => '/existing/path',
    ]);

    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('resolveGlobalExcludesFile')->once()->andReturn(null);

    $action = new BackfillGlobalGitignoreAction($mock);
    $action->handle($project->id, '/tmp/repo');

    expect($project->fresh()->global_gitignore_path)->toBe('/existing/path');
});

<?php

use App\Actions\ResolveProjectAction;
use App\Models\Project;
use App\Services\GitMetadataService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('returns project array for valid slug', function () {
    $project = Project::create([
        'slug' => 'my-project',
        'name' => 'my-project',
        'path' => '/tmp/my-project',
        'git_common_dir' => '/tmp/my-project/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => 'git@github.com:foo/bar.git',
    ]);

    $result = app(ResolveProjectAction::class)->handle('my-project');

    expect($result)->toBeArray();
    expect($result['id'])->toBe($project->id);
    expect($result['slug'])->toBe('my-project');
    expect($result['path'])->toBe('/tmp/my-project');
});

test('returns null for unknown slug', function () {
    $result = app(ResolveProjectAction::class)->handle('nonexistent');

    expect($result)->toBeNull();
});

test('backfills remote_url from git config when null', function () {
    Project::create([
        'slug' => 'pre-migration',
        'name' => 'pre-migration',
        'path' => '/tmp/pre-migration',
        'git_common_dir' => '/tmp/pre-migration/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => null,
    ]);

    $git = Mockery::mock(GitMetadataService::class);
    $git->shouldReceive('getRemoteUrl')
        ->once()
        ->with('/tmp/pre-migration')
        ->andReturn('git@github.com:acme/widgets.git');
    app()->instance(GitMetadataService::class, $git);

    $result = app(ResolveProjectAction::class)->handle('pre-migration');

    expect($result['remote_url'])->toBe('git@github.com:acme/widgets.git');
    expect(Project::where('slug', 'pre-migration')->value('remote_url'))
        ->toBe('git@github.com:acme/widgets.git');
});

test('skips git lookup when remote_url already stored', function () {
    Project::create([
        'slug' => 'already-set',
        'name' => 'already-set',
        'path' => '/tmp/already-set',
        'git_common_dir' => '/tmp/already-set/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => 'git@gitlab.com:team/repo.git',
    ]);

    $git = Mockery::mock(GitMetadataService::class);
    $git->shouldNotReceive('getRemoteUrl');
    app()->instance(GitMetadataService::class, $git);

    $result = app(ResolveProjectAction::class)->handle('already-set');

    expect($result['remote_url'])->toBe('git@gitlab.com:team/repo.git');
});

test('persists empty-string sentinel when project has no origin', function () {
    Project::create([
        'slug' => 'local-only',
        'name' => 'local-only',
        'path' => '/tmp/local-only',
        'git_common_dir' => '/tmp/local-only/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => null,
    ]);

    $git = Mockery::mock(GitMetadataService::class);
    $git->shouldReceive('getRemoteUrl')->once()->andReturn(null);
    app()->instance(GitMetadataService::class, $git);

    $result = app(ResolveProjectAction::class)->handle('local-only');

    expect($result['remote_url'])->toBe('');
    expect(Project::where('slug', 'local-only')->value('remote_url'))->toBe('');
});

test('skips git lookup once empty-string sentinel is stored', function () {
    Project::create([
        'slug' => 'no-origin',
        'name' => 'no-origin',
        'path' => '/tmp/no-origin',
        'git_common_dir' => '/tmp/no-origin/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => '',
    ]);

    $git = Mockery::mock(GitMetadataService::class);
    $git->shouldNotReceive('getRemoteUrl');
    app()->instance(GitMetadataService::class, $git);

    $result = app(ResolveProjectAction::class)->handle('no-origin');

    expect($result['remote_url'])->toBe('');
});

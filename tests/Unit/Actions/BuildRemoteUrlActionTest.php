<?php

use App\Actions\BuildRemoteUrlAction;
use App\Models\Project;
use App\Services\GitMetadataService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->testRepoPath = $this->createTempDirectory('rfa_build_remote_url_');
    $this->initTestRepo($this->testRepoPath);
    File::put($this->testRepoPath.'/file.txt', 'hello');
    $this->commitTestRepo($this->testRepoPath, 'init');
});

test('returns null for unknown slug', function () {
    expect(app(BuildRemoteUrlAction::class)->handle('does-not-exist', 'repo'))
        ->toBeNull();
});

test('returns null when project has no remote and none can be resolved', function () {
    $project = Project::create([
        'slug' => 'no-remote',
        'name' => 'no-remote',
        'path' => $this->testRepoPath,
        'git_common_dir' => $this->testRepoPath.'/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    expect(app(BuildRemoteUrlAction::class)->handle($project->slug, 'repo'))
        ->toBeNull();
});

test('returns null for a recognised-but-unsupported provider (e.g. bitbucket)', function () {
    $project = Project::create([
        'slug' => 'bitbucket-repo',
        'name' => 'bitbucket-repo',
        'path' => $this->testRepoPath,
        'git_common_dir' => $this->testRepoPath.'/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => 'git@bitbucket.org:team/project.git',
    ]);

    expect(app(BuildRemoteUrlAction::class)->handle($project->slug, 'repo'))
        ->toBeNull();
});

test('builds a github repo url from a stored remote', function () {
    $project = Project::create([
        'slug' => 'rfa',
        'name' => 'rfa',
        'path' => $this->testRepoPath,
        'git_common_dir' => $this->testRepoPath.'/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => 'git@github.com:fgilio/rfa.git',
    ]);

    $url = app(BuildRemoteUrlAction::class)->handle($project->slug, 'repo');

    expect($url)->toBe('https://github.com/fgilio/rfa');
});

test('builds a line url with a range anchor', function () {
    $project = Project::create([
        'slug' => 'rfa',
        'name' => 'rfa',
        'path' => $this->testRepoPath,
        'git_common_dir' => $this->testRepoPath.'/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => 'https://github.com/fgilio/rfa.git',
    ]);

    $url = app(BuildRemoteUrlAction::class)->handle($project->slug, 'line', [
        'ref' => 'main',
        'path' => 'README.md',
        'start' => 10,
        'end' => 20,
    ]);

    expect($url)->toBe('https://github.com/fgilio/rfa/blob/main/README.md#L10-L20');
});

test('self-heals a missing remote_url by reading git config and persisting it', function () {
    // Add a real origin to the test repo so GitMetadataService can find it.
    $this->runTestRepoCommand($this->testRepoPath, 'git remote add origin git@github.com:fgilio/rfa.git');

    $project = Project::create([
        'slug' => 'rfa',
        'name' => 'rfa',
        'path' => $this->testRepoPath,
        'git_common_dir' => $this->testRepoPath.'/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'remote_url' => null,
    ]);

    $url = app(BuildRemoteUrlAction::class)->handle($project->slug, 'repo');

    expect($url)->toBe('https://github.com/fgilio/rfa');
    expect($project->fresh()->remote_url)->toBe('git@github.com:fgilio/rfa.git');
});

test('getRemoteUrl returns null when no origin is configured', function () {
    $service = app(GitMetadataService::class);

    expect($service->getRemoteUrl($this->testRepoPath))->toBeNull();
});

test('getRemoteUrl returns the configured origin url', function () {
    $this->runTestRepoCommand($this->testRepoPath, 'git remote add origin https://github.com/fgilio/rfa.git');

    $service = app(GitMetadataService::class);

    expect($service->getRemoteUrl($this->testRepoPath))->toBe('https://github.com/fgilio/rfa.git');
});

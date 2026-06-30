<?php

use App\Actions\RegisterProjectAction;
use App\Exceptions\NotAGitRepositoryException;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->testRepoPath = $this->createTempDirectory('rfa_register_test_');
    $this->initTestRepo($this->testRepoPath);
    File::put($this->testRepoPath.'/file.txt', 'hello');
    $this->commitTestRepo($this->testRepoPath, 'init');
});

test('registers new project from git directory', function () {
    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($project)->toBeInstanceOf(Project::class);
    expect($project->path)->toBe(realpath($this->testRepoPath));
    expect($project->name)->toBe(basename($this->testRepoPath));
    expect($project->slug)->not->toBeEmpty();
    expect($project->is_worktree)->toBeFalse();
    expect($project->branch)->toBe('main');
});

test('returns existing project on repeated registration (idempotent)', function () {
    $first = app(RegisterProjectAction::class)->handle($this->testRepoPath);
    $second = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($second->id)->toBe($first->id);
    expect(Project::count())->toBe(1);
});

test('seeds branch on first registration', function () {
    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($project->branch)->toBe('main');
});

test('seeds default base branch from main on first registration', function () {
    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($project->default_base_branch)->toBe('main');
});

test('seeds default base branch from master when the repo uses master', function () {
    $this->runTestRepoCommand($this->testRepoPath, 'git branch -m main master');

    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($project->default_base_branch)->toBe('master');
});

test('leaves default base branch unset when neither main nor master exists', function () {
    $this->runTestRepoCommand($this->testRepoPath, 'git branch -m main trunk');

    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($project->default_base_branch)->toBeNull();
});

test('does not overwrite a user-chosen base branch on re-registration', function () {
    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    // User narrows the review to a different base; re-registration must respect it.
    $project->update(['default_base_branch' => 'develop']);

    $refreshed = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($refreshed->default_base_branch)->toBe('develop');
});

test('does not overwrite branch on re-registration', function () {
    app(RegisterProjectAction::class)->handle($this->testRepoPath);

    // Switch branches externally - the review page's divergence logic owns subsequent writes.
    $this->runTestRepoCommand($this->testRepoPath, 'git checkout -b feature-x');

    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($project->branch)->toBe('main');
});

test('handles slug collisions with suffix', function () {
    // Create two repos with same basename
    $path2 = $this->createTempDirectory('rfa_register_test2_');
    File::makeDirectory($path2.'/'.basename($this->testRepoPath), 0755, true);
    $duplicatePath = $path2.'/'.basename($this->testRepoPath);
    $this->initTestRepo($duplicatePath);
    File::put($duplicatePath.'/file.txt', 'world');
    $this->commitTestRepo($duplicatePath, 'init');

    $first = app(RegisterProjectAction::class)->handle($this->testRepoPath);
    $second = app(RegisterProjectAction::class)->handle($duplicatePath);

    expect($second->slug)->toBe($first->slug.'-2');
});

test('throws NotAGitRepositoryException on non-git directory', function () {
    $nonGit = $this->createTempDirectory('rfa_nongit_');

    expect(fn () => app(RegisterProjectAction::class)->handle($nonGit))
        ->toThrow(NotAGitRepositoryException::class);
});

test('refreshes git_common_dir and is_worktree on re-registration', function () {
    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);
    $correctCommonDir = $project->git_common_dir;

    // Simulate stale worktree metadata from an earlier registration (e.g. the path
    // was later converted into/out of a git worktree).
    $project->update(['git_common_dir' => '/stale/path/.git', 'is_worktree' => true]);

    $refreshed = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($refreshed->id)->toBe($project->id);
    expect($refreshed->git_common_dir)->toBe($correctCommonDir);
    expect($refreshed->is_worktree)->toBeFalse();
});

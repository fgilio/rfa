<?php

use App\Actions\RegisterProjectAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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

test('updates branch on repeated registration', function () {
    app(RegisterProjectAction::class)->handle($this->testRepoPath);

    // Create and checkout a new branch
    $this->runTestRepoCommand($this->testRepoPath, 'git checkout -b feature-x');

    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($project->branch)->toBe('feature-x');
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

test('throws on non-git directory', function () {
    $nonGit = $this->createTempDirectory('rfa_nongit_');

    expect(fn () => app(RegisterProjectAction::class)->handle($nonGit))
        ->toThrow(RuntimeException::class);
});

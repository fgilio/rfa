<?php

use App\Actions\RegisterProjectAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->testRepoPath = sys_get_temp_dir().'/rfa_register_test_'.uniqid();
    File::makeDirectory($this->testRepoPath, 0755, true);

    File::put($this->testRepoPath.'/file.txt', 'hello');
    // gpgsign=false: disable GPG signing so test commits work without a key
    exec('cd '.escapeshellarg($this->testRepoPath).' && git init -b main && git config user.email "t@t" && git config user.name "T" && git config commit.gpgsign false && git add -A && git commit -m "init" 2>&1');
});

afterEach(function () {
    File::deleteDirectory($this->testRepoPath);
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
    exec('cd '.escapeshellarg($this->testRepoPath).' && git checkout -b feature-x 2>&1');

    $project = app(RegisterProjectAction::class)->handle($this->testRepoPath);

    expect($project->branch)->toBe('feature-x');
});

test('handles slug collisions with suffix', function () {
    // Create two repos with same basename
    $path2 = sys_get_temp_dir().'/rfa_register_test2_'.uniqid();
    File::makeDirectory($path2.'/'.basename($this->testRepoPath), 0755, true);
    $duplicatePath = $path2.'/'.basename($this->testRepoPath);
    File::put($duplicatePath.'/file.txt', 'world');
    // gpgsign=false: disable GPG signing so test commits work without a key
    exec('cd '.escapeshellarg($duplicatePath).' && git init -b main && git config user.email "t@t" && git config user.name "T" && git config commit.gpgsign false && git add -A && git commit -m "init" 2>&1');

    $first = app(RegisterProjectAction::class)->handle($this->testRepoPath);
    $second = app(RegisterProjectAction::class)->handle($duplicatePath);

    expect($second->slug)->toBe($first->slug.'-2');

    File::deleteDirectory($path2);
});

test('throws on non-git directory', function () {
    $nonGit = sys_get_temp_dir().'/rfa_nongit_'.uniqid();
    File::makeDirectory($nonGit);

    expect(fn () => app(RegisterProjectAction::class)->handle($nonGit))
        ->toThrow(\RuntimeException::class);

    File::deleteDirectory($nonGit);
});

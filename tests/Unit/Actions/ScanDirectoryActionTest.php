<?php

use App\Actions\RegisterProjectAction;
use App\Actions\ScanDirectoryAction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->parentDir = $this->createTempDirectory('rfa_scan_test_');
});

// -- helpers --

function createGitRepo(string $parentDir, string $name): string
{
    $path = $parentDir.'/'.$name;
    File::makeDirectory($path, 0755, true);
    test()->initTestRepo($path);
    File::put($path.'/file.txt', 'hello');
    test()->commitTestRepo($path, 'init');

    return $path;
}

// -- registration --

test('registers untracked git repos found in directory', function () {
    createGitRepo($this->parentDir, 'repo-a');
    createGitRepo($this->parentDir, 'repo-b');
    File::makeDirectory($this->parentDir.'/not-a-repo', 0755, true);

    $result = app(ScanDirectoryAction::class)->handle($this->parentDir);

    expect($result->found)->toBe(2);
    expect($result->registered)->toBe(2);
    expect($result->alreadyTracked)->toBe(0);
    expect($result->failed)->toBe(0);
    expect($result->newProjects)->toHaveCount(2);
});

test('skips already tracked repos', function () {
    $repoA = createGitRepo($this->parentDir, 'repo-a');
    createGitRepo($this->parentDir, 'repo-b');

    // Pre-register repo-a
    app(RegisterProjectAction::class)->handle($repoA);

    $result = app(ScanDirectoryAction::class)->handle($this->parentDir);

    expect($result->found)->toBe(2);
    expect($result->registered)->toBe(1);
    expect($result->alreadyTracked)->toBe(1);
    expect($result->newProjects)->toHaveCount(1);
    expect($result->newProjects[0]['name'])->toBe('repo-b');
});

// -- filtering --

test('ignores non-git directories', function () {
    File::makeDirectory($this->parentDir.'/plain-dir', 0755, true);
    File::makeDirectory($this->parentDir.'/another-dir', 0755, true);

    $result = app(ScanDirectoryAction::class)->handle($this->parentDir);

    expect($result->found)->toBe(0);
    expect($result->registered)->toBe(0);
});

test('does not recurse into nested directories', function () {
    // Git repo is two levels deep - should not be found
    $nested = $this->parentDir.'/wrapper/deep-repo';
    File::makeDirectory($nested, 0755, true);
    $this->initTestRepo($nested);
    File::put($nested.'/file.txt', 'hello');
    $this->commitTestRepo($nested, 'init');

    $result = app(ScanDirectoryAction::class)->handle($this->parentDir);

    expect($result->found)->toBe(0);
});

test('skips child whose top-level resolves to a different path', function () {
    // Create a repo at first level
    $repoPath = createGitRepo($this->parentDir, 'real-repo');

    // Create a sibling that is actually a subdirectory symlinked from the repo
    $subdir = $repoPath.'/subdir';
    File::makeDirectory($subdir, 0755, true);
    symlink($subdir, $this->parentDir.'/linked-subdir');

    $result = app(ScanDirectoryAction::class)->handle($this->parentDir);

    // Only the real repo root should be found, not the symlinked subdir
    expect($result->found)->toBe(1);
    expect($result->registered)->toBe(1);
    expect($result->newProjects[0]['name'])->toBe('real-repo');
});

// -- edge cases --

test('handles empty directory', function () {
    $result = app(ScanDirectoryAction::class)->handle($this->parentDir);

    expect($result->found)->toBe(0);
    expect($result->registered)->toBe(0);
    expect($result->alreadyTracked)->toBe(0);
});

test('throws on non-existent directory', function () {
    expect(fn () => app(ScanDirectoryAction::class)->handle('/nonexistent/path'))
        ->toThrow(InvalidArgumentException::class);
});

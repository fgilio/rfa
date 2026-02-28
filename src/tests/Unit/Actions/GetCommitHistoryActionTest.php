<?php

use App\Actions\GetCommitHistoryAction;
use App\Services\GitDiffService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_commithistory_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
        'git config commit.gpgsign false',
    ]));

    File::put($this->tmpDir.'/file.txt', "ok\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('returns commits as arrays with all fields', function () {
    $action = new GetCommitHistoryAction(new GitDiffService(new IgnoreService));
    $commits = $action->handle($this->tmpDir);

    expect($commits)->toHaveCount(1)
        ->and($commits[0])->toHaveKeys(['hash', 'shortHash', 'message', 'author', 'relativeDate', 'date'])
        ->and($commits[0]['message'])->toBe('init');
});

test('respects limit and offset parameters', function () {
    File::put($this->tmpDir.'/file.txt', "v2\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m second');

    File::put($this->tmpDir.'/file.txt', "v3\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m third');

    $action = new GetCommitHistoryAction(new GitDiffService(new IgnoreService));

    $limited = $action->handle($this->tmpDir, limit: 1);
    expect($limited)->toHaveCount(1)
        ->and($limited[0]['message'])->toBe('third');

    $offset = $action->handle($this->tmpDir, limit: 1, offset: 1);
    expect($offset)->toHaveCount(1)
        ->and($offset[0]['message'])->toBe('second');
});

test('returns commits for specific branch', function () {
    exec('cd '.escapeshellarg($this->tmpDir).' && git checkout -b feature');
    File::put($this->tmpDir.'/file.txt', "feature\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m feature-work');

    exec('cd '.escapeshellarg($this->tmpDir).' && git checkout main');

    $action = new GetCommitHistoryAction(new GitDiffService(new IgnoreService));
    $commits = $action->handle($this->tmpDir, branch: 'feature');

    expect($commits)->toHaveCount(2)
        ->and($commits[0]['message'])->toBe('feature-work');
});

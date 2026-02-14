<?php

use App\Actions\LoadFileDiffAction;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_action_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
    ]));

    File::put($this->tmpDir.'/hello.txt', "line1\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('returns view array for modified file', function () {
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser);
    $result = $action->handle($this->tmpDir, 'hello.txt');

    expect($result)->toHaveKeys(['hunks', 'tooLarge']);
    expect($result['tooLarge'])->toBeFalse();
    expect($result['hunks'])->toHaveCount(1);
    expect($result['hunks'][0])->toHaveKeys(['header', 'oldStart', 'newStart', 'lines']);
});

test('returns tooLarge true when diff exceeds limit', function () {
    File::put($this->tmpDir.'/hello.txt', str_repeat("long line\n", 500));

    // Use a very low maxBytes config
    config(['rfa.diff_max_bytes' => 100]);

    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser);
    $result = $action->handle($this->tmpDir, 'hello.txt');

    expect($result)->toBe(['hunks' => [], 'tooLarge' => true]);
});

test('returns null for empty diff', function () {
    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser);
    $result = $action->handle($this->tmpDir, 'nonexistent.txt', isUntracked: true);

    expect($result)->toBeNull();
});

test('handles untracked file', function () {
    File::put($this->tmpDir.'/newfile.txt', "hello\nworld\n");

    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser);
    $result = $action->handle($this->tmpDir, 'newfile.txt', isUntracked: true);

    expect($result)->not->toBeNull();
    expect($result['hunks'])->toHaveCount(1);
    expect($result['tooLarge'])->toBeFalse();
});

<?php

use App\Actions\GetFileListAction;
use App\Services\GitDiffService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_filelist_test_'.uniqid();
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

test('returns files as arrays with id field', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new IgnoreService));
    $files = $action->handle($this->tmpDir);

    expect($files)->toHaveCount(1);
    expect($files[0])->toHaveKeys(['id', 'path', 'status', 'additions', 'deletions', 'isBinary', 'isUntracked']);
    expect($files[0]['id'])->toStartWith('file-');
    expect($files[0]['path'])->toBe('file.txt');
});

test('clears cache by default', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new IgnoreService));
    $files = $action->handle($this->tmpDir);

    $cacheKey = 'rfa_diff_v3_'.hash('xxh128', $this->tmpDir.':'.$files[0]['id']);
    Cache::put($cacheKey, 'stale', 60);

    $action->handle($this->tmpDir);

    expect(Cache::has($cacheKey))->toBeFalse();
});

test('preserves cache when clearCache is false', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new IgnoreService));
    $files = $action->handle($this->tmpDir, clearCache: false);

    $cacheKey = 'rfa_diff_v3_'.hash('xxh128', $this->tmpDir.':'.$files[0]['id']);
    Cache::put($cacheKey, 'kept', 60);

    $action->handle($this->tmpDir, clearCache: false);

    expect(Cache::get($cacheKey))->toBe('kept');
});

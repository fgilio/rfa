<?php

use App\Actions\GetFileListAction;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_filelist_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    commitTestRepo($this->tmpDir, 'init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('returns files as arrays with id field', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $files = $action->handle($this->tmpDir);

    expect($files)->toHaveCount(1);
    expect($files[0])->toHaveKeys(['id', 'path', 'status', 'additions', 'deletions', 'isBinary', 'isUntracked']);
    expect($files[0]['id'])->toStartWith('file-');
    expect($files[0]['path'])->toBe('file.txt');
});

test('clears cache by default', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $files = $action->handle($this->tmpDir);

    $cacheKey = 'rfa_diff_v5_'.hash('xxh128', $this->tmpDir.':working:'.$files[0]['id'].':light');
    Cache::put($cacheKey, 'stale', 60);

    $action->handle($this->tmpDir);

    expect(Cache::has($cacheKey))->toBeFalse();
});

test('preserves cache when clearCache is false', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $files = $action->handle($this->tmpDir, clearCache: false);

    $cacheKey = 'rfa_diff_v5_'.hash('xxh128', $this->tmpDir.':working:'.$files[0]['id'].':light');
    Cache::put($cacheKey, 'kept', 60);

    $action->handle($this->tmpDir, clearCache: false);

    expect(Cache::get($cacheKey))->toBe('kept');
});

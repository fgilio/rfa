<?php

use App\Actions\GetFileListAction;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_filelist_test_');

    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'init');
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

    $cacheKey = DiffCacheKey::for($this->tmpDir, $files[0]['id']);
    Cache::put($cacheKey, 'stale', 60);

    $action->handle($this->tmpDir);

    expect(Cache::has($cacheKey))->toBeFalse();
});

test('preserves cache when clearCache is false', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $files = $action->handle($this->tmpDir, clearCache: false);

    $cacheKey = DiffCacheKey::for($this->tmpDir, $files[0]['id']);
    Cache::put($cacheKey, 'kept', 60);

    $action->handle($this->tmpDir, clearCache: false);

    expect(Cache::get($cacheKey))->toBe('kept');
});

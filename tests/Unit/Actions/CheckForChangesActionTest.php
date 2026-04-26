<?php

use App\Actions\CheckForChangesAction;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_changes_test_');

    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'init');
});

test('returns fingerprint and count for changed repo', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new CheckForChangesAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $result = $action->handle($this->tmpDir);

    expect($result)
        ->toHaveKey('fingerprint')
        ->toHaveKey('count');
    expect($result['fingerprint'])->toBeString()->not->toBeEmpty();
    expect($result['count'])->toBe(1);
});

test('returns same fingerprint for unchanged repo', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new CheckForChangesAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $first = $action->handle($this->tmpDir);
    $second = $action->handle($this->tmpDir);

    expect($first['fingerprint'])->toBe($second['fingerprint']);
    expect($first['count'])->toBe($second['count']);
});

test('returns different fingerprint after file modification', function () {
    $action = new CheckForChangesAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $before = $action->handle($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "changed\n");
    $after = $action->handle($this->tmpDir);

    expect($after['fingerprint'])->not->toBe($before['fingerprint']);
    expect($after['count'])->toBe(1);
    expect($before['count'])->toBe(0);
});

test('returns different fingerprint after adding untracked file', function () {
    $action = new CheckForChangesAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $before = $action->handle($this->tmpDir);

    File::put($this->tmpDir.'/newfile.txt', "hello\n");
    $after = $action->handle($this->tmpDir);

    expect($after['fingerprint'])->not->toBe($before['fingerprint']);
    expect($after['count'])->toBe(1);
});

test('returns different fingerprint after deleting tracked file', function () {
    $action = new CheckForChangesAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $before = $action->handle($this->tmpDir);

    File::delete($this->tmpDir.'/file.txt');
    $after = $action->handle($this->tmpDir);

    expect($after['fingerprint'])->not->toBe($before['fingerprint']);
    expect($after['count'])->toBe(1);
});

test('count tracks multiple changes', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");
    File::put($this->tmpDir.'/another.txt', "new\n");

    $action = new CheckForChangesAction(new GitDiffService(new GitProcessService, new IgnoreService));
    $result = $action->handle($this->tmpDir);

    expect($result['count'])->toBe(2);
});

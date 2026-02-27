<?php

use App\Actions\CheckForChangesAction;
use App\Services\GitDiffService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_changes_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
    ]));

    File::put($this->tmpDir.'/file.txt', "ok\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('returns non-empty string hash', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new CheckForChangesAction(new GitDiffService(new IgnoreService));
    $hash = $action->handle($this->tmpDir);

    expect($hash)->toBeString()->not->toBeEmpty();
});

test('returns same hash for unchanged repo', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new CheckForChangesAction(new GitDiffService(new IgnoreService));
    $hash1 = $action->handle($this->tmpDir);
    $hash2 = $action->handle($this->tmpDir);

    expect($hash1)->toBe($hash2);
});

test('returns different hash after file modification', function () {
    $action = new CheckForChangesAction(new GitDiffService(new IgnoreService));
    $before = $action->handle($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "changed\n");
    $after = $action->handle($this->tmpDir);

    expect($after)->not->toBe($before);
});

test('returns different hash after adding untracked file', function () {
    $action = new CheckForChangesAction(new GitDiffService(new IgnoreService));
    $before = $action->handle($this->tmpDir);

    File::put($this->tmpDir.'/newfile.txt', "hello\n");
    $after = $action->handle($this->tmpDir);

    expect($after)->not->toBe($before);
});

test('returns different hash after deleting tracked file', function () {
    $action = new CheckForChangesAction(new GitDiffService(new IgnoreService));
    $before = $action->handle($this->tmpDir);

    File::delete($this->tmpDir.'/file.txt');
    $after = $action->handle($this->tmpDir);

    expect($after)->not->toBe($before);
});

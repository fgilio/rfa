<?php

use App\Actions\GetCommitHistoryAction;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_commithistory_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    commitTestRepo($this->tmpDir, 'init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('returns commits as arrays with all fields', function () {
    $action = new GetCommitHistoryAction(new GitMetadataService(new GitProcessService));
    $commits = $action->handle($this->tmpDir);

    expect($commits)->toHaveCount(1)
        ->and($commits[0])->toHaveKeys(['hash', 'shortHash', 'message', 'author', 'relativeDate', 'date'])
        ->and($commits[0]['message'])->toBe('init');
});

test('respects limit and offset parameters', function () {
    File::put($this->tmpDir.'/file.txt', "v2\n");
    commitTestRepo($this->tmpDir, 'second');

    File::put($this->tmpDir.'/file.txt', "v3\n");
    commitTestRepo($this->tmpDir, 'third');

    $action = new GetCommitHistoryAction(new GitMetadataService(new GitProcessService));

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
    commitTestRepo($this->tmpDir, 'feature-work');

    exec('cd '.escapeshellarg($this->tmpDir).' && git checkout main');

    $action = new GetCommitHistoryAction(new GitMetadataService(new GitProcessService));
    $commits = $action->handle($this->tmpDir, branch: 'feature');

    expect($commits)->toHaveCount(2)
        ->and($commits[0]['message'])->toBe('feature-work');
});

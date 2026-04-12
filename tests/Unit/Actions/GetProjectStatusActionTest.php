<?php

use App\Actions\GetProjectStatusAction;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_status_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'init');

    $this->action = new GetProjectStatusAction(
        new GitDiffService(new GitProcessService, new IgnoreService)
    );
});

test('counts additions and deletions from source files', function () {
    File::put($this->tmpDir.'/file.txt', "changed\nline\n");

    $result = $this->action->handle($this->tmpDir);

    expect($result)
        ->dirty->toBeTrue()
        ->fileCount->toBe(1)
        ->additions->toBe(2)
        ->deletions->toBe(1);
});

test('excludes rfa review files from metrics', function () {
    File::ensureDirectoryExists($this->tmpDir.'/.rfa');
    File::put($this->tmpDir.'/.rfa/20260410_095500_comments_AbCd1234.json', '{"schema_version":1}');
    File::put($this->tmpDir.'/.rfa/20260410_095500_comments_AbCd1234.md', "# Review\nsome content\n");

    $result = $this->action->handle($this->tmpDir);

    expect($result)
        ->dirty->toBeFalse()
        ->fileCount->toBe(0)
        ->additions->toBe(0)
        ->deletions->toBe(0);
});

test('counts only source files when both source and review files exist', function () {
    File::put($this->tmpDir.'/new.txt', "hello\n");

    File::ensureDirectoryExists($this->tmpDir.'/.rfa');
    File::put($this->tmpDir.'/.rfa/20260410_095500_comments_AbCd1234.json', '{"schema_version":1}');
    File::put($this->tmpDir.'/.rfa/20260410_095500_comments_AbCd1234.md', "# Review\n");

    $result = $this->action->handle($this->tmpDir);

    expect($result)
        ->dirty->toBeTrue()
        ->fileCount->toBe(1)
        ->additions->toBe(1)
        ->deletions->toBe(0);
});

test('returns clean status for unchanged repo', function () {
    $result = $this->action->handle($this->tmpDir);

    expect($result)
        ->dirty->toBeFalse()
        ->fileCount->toBe(0)
        ->additions->toBe(0)
        ->deletions->toBe(0);
});

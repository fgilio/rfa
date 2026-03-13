<?php

use App\Actions\GetProjectStatusAction;
use App\Exceptions\GitCommandException;
use App\Services\GitDiffService;

test('returns dirty status with file counts', function () {
    $mock = Mockery::mock(GitDiffService::class);
    $mock->shouldReceive('getFileList')
        ->once()
        ->andReturn([
            ['path' => 'file1.txt', 'additions' => 10, 'deletions' => 3],
            ['path' => 'file2.txt', 'additions' => 5, 'deletions' => 2],
        ]);

    $action = new GetProjectStatusAction($mock);
    $result = $action->handle('/tmp/repo');

    expect($result)->toBe([
        'dirty' => true,
        'fileCount' => 2,
        'additions' => 15,
        'deletions' => 5,
    ]);
});

test('returns clean status for empty file list', function () {
    $mock = Mockery::mock(GitDiffService::class);
    $mock->shouldReceive('getFileList')->once()->andReturn([]);

    $action = new GetProjectStatusAction($mock);
    $result = $action->handle('/tmp/repo');

    expect($result)->toBe([
        'dirty' => false,
        'fileCount' => 0,
        'additions' => 0,
        'deletions' => 0,
    ]);
});

test('returns clean status on git command exception', function () {
    $mock = Mockery::mock(GitDiffService::class);
    $mock->shouldReceive('getFileList')
        ->once()
        ->andThrow(new GitCommandException('git diff', 'error', 128));

    $action = new GetProjectStatusAction($mock);
    $result = $action->handle('/tmp/repo');

    expect($result)->toBe([
        'dirty' => false,
        'fileCount' => 0,
        'additions' => 0,
        'deletions' => 0,
    ]);
});

test('passes global gitignore path to service', function () {
    $mock = Mockery::mock(GitDiffService::class);
    $mock->shouldReceive('getFileList')
        ->with('/tmp/repo', '/home/user/.gitignore')
        ->once()
        ->andReturn([]);

    $action = new GetProjectStatusAction($mock);
    $action->handle('/tmp/repo', '/home/user/.gitignore');
});

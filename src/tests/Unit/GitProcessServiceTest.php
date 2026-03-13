<?php

use App\Exceptions\GitCommandException;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

test('runs git command and returns output', function () {
    $repoPath = $this->createTempDirectory('rfa_gps_test_');
    $this->initTestRepo($repoPath);

    $service = new GitProcessService;
    $output = $service->run($repoPath, ['status', '--porcelain']);

    expect($output)->toBeString();
});

test('throws git command exception on failure', function () {
    $repoPath = $this->createTempDirectory('rfa_gps_test_');
    $this->initTestRepo($repoPath);

    $service = new GitProcessService;

    try {
        $service->run($repoPath, ['log', '--oneline', '-1', 'nonexistent-ref-abc123']);
        $this->fail('Expected GitCommandException');
    } catch (GitCommandException $e) {
        expect($e->exitCode)->toBeGreaterThan(0)
            ->and($e->command)->toContain('log')
            ->and($e->stderr)->toBeString();
    }
});

test('returns raw process output', function () {
    $repoPath = $this->createTempDirectory('rfa_gps_test_');
    $this->initTestRepo($repoPath);
    File::put($repoPath.'/file.txt', 'hello');
    $this->commitTestRepo($repoPath, 'initial commit');

    $service = new GitProcessService;
    $output = $service->run($repoPath, ['log', '--oneline', '-1']);

    expect($output)->toContain('initial commit');
});

test('handles unicode file paths without quoting', function () {
    $repoPath = $this->createTempDirectory('rfa_gps_test_');
    $this->initTestRepo($repoPath);
    File::put($repoPath.'/日本語.txt', 'content');
    $this->commitTestRepo($repoPath, 'add unicode file');

    $service = new GitProcessService;
    $output = $service->run($repoPath, ['ls-files']);

    expect($output)->toContain('日本語.txt');
});

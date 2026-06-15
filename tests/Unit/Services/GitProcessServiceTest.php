<?php

use App\Exceptions\GitCommandException;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_gps_');
    $this->initTestRepo($this->repoPath);
    File::put($this->repoPath.'/file.txt', "hello\n");
    $this->commitTestRepo($this->repoPath, 'initial commit');

    $this->service = new GitProcessService;
});

test('returns stdout for successful commands', function () {
    $output = $this->service->run($this->repoPath, ['log', '--oneline', '-1']);

    expect($output)->toContain('initial commit');
});

test('throws GitCommandException with command, stderr, and exit code on failure', function () {
    try {
        $this->service->run($this->repoPath, ['log', '--oneline', '-1', 'nonexistent-ref-deadbeef']);
        $this->fail('Expected GitCommandException');
    } catch (GitCommandException $e) {
        expect($e->exitCode)->toBeGreaterThan(0)
            ->and($e->command)->toStartWith('git log')
            ->and($e->stderr)->toBeString()
            ->and($e->stderr)->not->toBe('');
    }
});

test('passes core.quotepath=false so unicode paths come back unquoted', function () {
    File::put($this->repoPath.'/日本語.txt', "x\n");
    $this->commitTestRepo($this->repoPath, 'add unicode file');

    $output = $this->service->run($this->repoPath, ['ls-files']);

    expect($output)->toContain('日本語.txt');
});

test('forces parseable diff prefixes over repo config', function () {
    $this->runTestRepoCommand($this->repoPath, [
        'git config diff.noprefix true',
        'git config diff.mnemonicPrefix true',
        'git config diff.srcPrefix custom-old/',
        'git config diff.dstPrefix custom-new/',
    ]);

    File::put($this->repoPath.'/file.txt', "changed\n");

    $output = $this->service->run($this->repoPath, ['diff', '--no-ext-diff', '--no-color', '--', 'file.txt']);

    expect($output)->toContain('diff --git a/file.txt b/file.txt')
        ->and($output)->toContain('--- a/file.txt')
        ->and($output)->toContain('+++ b/file.txt')
        ->and($output)->not->toContain('custom-old/')
        ->and($output)->not->toContain('custom-new/');
});

test('pins plain diff colors so user themes cannot mimic moved-line codes', function () {
    $this->runTestRepoCommand($this->repoPath, [
        'git config color.diff.old magenta',
        'git config color.diff.new cyan',
    ]);

    File::put($this->repoPath.'/file.txt', "changed\n");

    $output = $this->service->run($this->repoPath, ['diff', '--no-ext-diff', '--color=always', '--', 'file.txt']);

    expect($output)->toContain("\x1b[31m-hello")
        ->and($output)->toContain("\x1b[32m+")
        ->and($output)->not->toContain("\x1b[35m-")
        ->and($output)->not->toContain("\x1b[36m+");
});

<?php

use App\Exceptions\GitCommandException;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_branch_exists_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'init');

    $this->service = new GitMetadataService(new GitProcessService);
});

test('returns true for a branch that exists', function () {
    expect($this->service->branchExists($this->tmpDir, 'main'))->toBeTrue();
});

test('returns false for a confirmed absent branch', function () {
    expect($this->service->branchExists($this->tmpDir, 'does-not-exist'))->toBeFalse();
});

test('returns false for syntactically invalid branch names without running git', function () {
    expect($this->service->branchExists($this->tmpDir, ''))->toBeFalse()
        ->and($this->service->branchExists($this->tmpDir, '-dashed'))->toBeFalse();
});

test('returns null when the existence probe fails for a reason other than a missing ref', function () {
    // exitCode 128 (e.g. "not a git repository") is a failure we can't read as a
    // definitive absence — the branch may well still exist.
    $service = new GitMetadataService(new class extends GitProcessService
    {
        public function run(string $repoPath, array $args): string
        {
            throw new GitCommandException(command: 'git '.implode(' ', $args), stderr: 'fatal: not a git repository', exitCode: 128);
        }
    });

    expect($service->branchExists('/tmp/whatever', 'main'))->toBeNull();
});

test('returns null when the existence probe throws a non-git failure', function () {
    $service = new GitMetadataService(new class extends GitProcessService
    {
        public function run(string $repoPath, array $args): string
        {
            throw new RuntimeException('process timed out');
        }
    });

    expect($service->branchExists('/tmp/whatever', 'main'))->toBeNull();
});

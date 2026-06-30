<?php

use App\Exceptions\GitCommandException;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_detect_base_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'init');

    $this->service = new GitMetadataService(new GitProcessService);
});

test('detects main when only main exists', function () {
    expect($this->service->detectDefaultBaseBranch($this->tmpDir))->toBe('main');
});

test('detects master when only master exists', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git branch -m main master');

    expect($this->service->detectDefaultBaseBranch($this->tmpDir))->toBe('master');
});

test('favours main when both main and master exist', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git branch master');

    expect($this->service->detectDefaultBaseBranch($this->tmpDir))->toBe('main');
});

test('returns null when neither main nor master exists', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git branch -m main trunk');

    expect($this->service->detectDefaultBaseBranch($this->tmpDir))->toBeNull();
});

test('does not fall through to master when the main probe is indeterminate', function () {
    // `main` probe fails for an unknowable reason (exit 128 / timeout), while
    // `master` definitively exists. We must not seed `master` off the back of an
    // indeterminate `main` probe — `main` may well be there.
    $service = new GitMetadataService(new class extends GitProcessService
    {
        public function run(string $repoPath, array $args): string
        {
            if (in_array('refs/heads/main', $args, true)) {
                throw new GitCommandException(command: 'git '.implode(' ', $args), stderr: 'fatal: lock', exitCode: 128);
            }

            return '';
        }
    });

    expect($service->detectDefaultBaseBranch('/tmp/whatever'))->toBeNull();
});

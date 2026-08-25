<?php

use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->repoDir = $this->createTempDirectory('rfa_test_repo_helper_');
    $this->initTestRepo($this->repoDir);
});

test('fixture repos never run git auto gc or auto maintenance', function () {
    // Auto-gc racing rapid commit loops can delete loose objects mid-test
    // ("bad tree object HEAD", issue #133). Both knobs must be off in every
    // repo copied from the template, independent of the caller's env.
    expect(trim($this->runTestRepoCommand($this->repoDir, 'git config --type=int gc.auto')))->toBe('0')
        ->and(trim($this->runTestRepoCommand($this->repoDir, 'git config --type=bool maintenance.auto')))->toBe('false');
});

test('failed repo commands append object store forensics to the exception', function () {
    File::put($this->repoDir.'/tracked.txt', "content\n");
    $this->commitTestRepo($this->repoDir, 'initial');

    try {
        $this->runTestRepoCommand($this->repoDir, 'git cat-file -t 0000000000000000000000000000000000000000');
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('Test repository command failed')
            ->toContain('Object store state:')
            ->toContain('count:');
    }
});

test('fixture repos ignore a hostile global git config', function () {
    // The harness must beat the developer's machine, not just an empty CI box:
    // a global core.excludesFile with "*.log" would otherwise leave `git add -A`
    // with nothing to stage and fail the initial commit.
    $configDir = $this->createTempDirectory('rfa_test_git_global_');
    $excludesFile = $configDir.'/excludes';
    $globalConfig = $configDir.'/config';

    File::put($excludesFile, "*.log\n");
    File::put($globalConfig, "[core]\n\texcludesFile = {$excludesFile}\n");

    $previous = getenv('GIT_CONFIG_GLOBAL');
    putenv('GIT_CONFIG_GLOBAL='.$globalConfig);

    try {
        File::put($this->repoDir.'/debug.log', "entry\n");
        $this->commitTestRepo($this->repoDir, 'initial');

        expect(trim($this->runTestRepoCommand($this->repoDir, 'git ls-files')))->toBe('debug.log');
    } finally {
        $previous === false
            ? putenv('GIT_CONFIG_GLOBAL')
            : putenv('GIT_CONFIG_GLOBAL='.$previous);
    }
});

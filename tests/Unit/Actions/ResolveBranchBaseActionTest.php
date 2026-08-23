<?php

use App\Actions\ResolveBranchBaseAction;
use App\Enums\BranchBaseState;
use App\Enums\BranchBaseUnavailableReason;
use App\Exceptions\GitCommandException;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_branch_base_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/a.txt', "root\n");
    $this->commitTestRepo($this->tmpDir, 'shared');
    $this->sharedSha = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    $this->action = new ResolveBranchBaseAction(new GitProcessService);
});

test('returns Ready with newest-first hashes when feature branch is ahead of base', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');

    File::put($this->tmpDir.'/b.txt', "first\n");
    $this->commitTestRepo($this->tmpDir, 'first ahead');
    $first = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    File::put($this->tmpDir.'/b.txt', "second\n");
    $this->commitTestRepo($this->tmpDir, 'second ahead');
    $second = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    $result = $this->action->handle($this->tmpDir, 'main', 'feature');

    expect($result->state)->toBe(BranchBaseState::Ready)
        ->and($result->baseBranch)->toBe('main')
        ->and($result->baseSha)->toBe($this->sharedSha)
        ->and($result->hashesInRange)->toBe([$second, $first]);
});

test('returns UpToDate when HEAD has no commits ahead of the base', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');

    $result = $this->action->handle($this->tmpDir, 'main', 'feature');

    expect($result->state)->toBe(BranchBaseState::UpToDate)
        ->and($result->baseBranch)->toBe('main')
        ->and($result->baseSha)->toBe($this->sharedSha)
        ->and($result->hashesInRange)->toBeEmpty();
});

test('returns OnBaseBranch when current branch is the configured base', function () {
    $result = $this->action->handle($this->tmpDir, 'main', 'main');

    expect($result->state)->toBe(BranchBaseState::OnBaseBranch)
        ->and($result->baseBranch)->toBe('main')
        ->and($result->baseSha)->toBeNull()
        ->and($result->hashesInRange)->toBeEmpty();
});

test('returns NotConfigured when base is null', function () {
    $result = $this->action->handle($this->tmpDir, null, 'feature');

    expect($result->state)->toBe(BranchBaseState::NotConfigured)
        ->and($result->baseBranch)->toBeNull();
});

test('returns NotConfigured when base is empty or whitespace', function () {
    $result = $this->action->handle($this->tmpDir, '   ', 'feature');

    expect($result->state)->toBe(BranchBaseState::NotConfigured);
});

test('returns MissingRef when base branch does not exist locally', function () {
    $result = $this->action->handle($this->tmpDir, 'origin/nonexistent', 'main');

    expect($result->state)->toBe(BranchBaseState::MissingRef)
        ->and($result->baseBranch)->toBe('origin/nonexistent')
        ->and($result->unavailableReason)->toBeNull();
});

test('returns Unavailable for unrelated histories', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git checkout --orphan feature');
    $this->runTestRepoCommand($this->tmpDir, 'git rm -rf .');
    File::put($this->tmpDir.'/unrelated.txt', "unrelated\n");
    $this->commitTestRepo($this->tmpDir, 'unrelated root');

    $result = $this->action->handle($this->tmpDir, 'main', 'feature');

    expect($result->state)->toBe(BranchBaseState::Unavailable)
        ->and($result->unavailableReason)->toBe(BranchBaseUnavailableReason::UnrelatedHistory)
        ->and($result->baseBranch)->toBe('main')
        ->and($result->baseSha)->toBeNull();
});

test('returns Unavailable when ref resolution fails', function () {
    $result = $this->action->handle('/path/that/does/not/exist', 'main', 'feature');

    expect($result->state)->toBe(BranchBaseState::Unavailable)
        ->and($result->unavailableReason)->toBe(BranchBaseUnavailableReason::CommandFailed)
        ->and($result->baseBranch)->toBe('main')
        ->and($result->baseSha)->toBeNull();
});

test('returns Unavailable when listing the resolved range fails', function () {
    $git = Mockery::mock(GitProcessService::class);
    $git->shouldReceive('run')
        ->once()
        ->with('/tmp/repo', ['rev-parse', '--verify', '--quiet', '--end-of-options', 'main^{commit}'])
        ->andReturn("base-sha\n");
    $git->shouldReceive('run')
        ->once()
        ->with('/tmp/repo', ['merge-base', 'base-sha', 'HEAD'])
        ->andReturn("merge-base\n");
    $git->shouldReceive('run')
        ->once()
        ->with('/tmp/repo', ['log', '--format=%H', 'merge-base..HEAD'])
        ->andThrow(new GitCommandException('git log', 'failed', 128));

    $result = (new ResolveBranchBaseAction($git))->handle('/tmp/repo', 'main', 'feature');

    expect($result->state)->toBe(BranchBaseState::Unavailable)
        ->and($result->unavailableReason)->toBe(BranchBaseUnavailableReason::CommandFailed)
        ->and($result->baseSha)->toBe('merge-base')
        ->and($result->hashesInRange)->toBeEmpty();
});

test('returns Unavailable when merge-base times out', function () {
    $process = new Process(['git', 'merge-base']);
    $process->setTimeout(30);
    $git = Mockery::mock(GitProcessService::class);
    $git->shouldReceive('run')->once()->andReturn("base-sha\n");
    $git->shouldReceive('run')->once()->andThrow(
        new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL),
    );

    $result = (new ResolveBranchBaseAction($git))->handle('/tmp/repo', 'main', 'feature');

    expect($result->state)->toBe(BranchBaseState::Unavailable)
        ->and($result->unavailableReason)->toBe(BranchBaseUnavailableReason::CommandFailed);
});

test('returns Unavailable when merge-base cannot start', function () {
    $git = Mockery::mock(GitProcessService::class);
    $git->shouldReceive('run')->once()->andReturn("base-sha\n");
    $git->shouldReceive('run')->once()->andThrow(
        new ProcessStartFailedException(new Process(['git', 'merge-base']), 'unable to start'),
    );

    $result = (new ResolveBranchBaseAction($git))->handle('/tmp/repo', 'main', 'feature');

    expect($result->state)->toBe(BranchBaseState::Unavailable)
        ->and($result->unavailableReason)->toBe(BranchBaseUnavailableReason::CommandFailed);
});

test('treats detached HEAD (null currentBranch) as not on base branch', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');
    File::put($this->tmpDir.'/b.txt', "first\n");
    $this->commitTestRepo($this->tmpDir, 'first ahead');

    $result = $this->action->handle($this->tmpDir, 'main', null);

    expect($result->state)->toBe(BranchBaseState::Ready)
        ->and($result->hashesInRange)->toHaveCount(1);
});

test('handles dash-prefixed base branch as missing ref (not as a flag)', function () {
    $result = $this->action->handle($this->tmpDir, '--exec=bad', 'main');

    expect($result->state)->toBe(BranchBaseState::MissingRef);
});

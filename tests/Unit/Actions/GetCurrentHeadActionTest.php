<?php

use App\Actions\GetCurrentHeadAction;
use App\DTOs\CurrentHeadResult;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_current_head_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'init');

    $this->action = new GetCurrentHeadAction(new GitMetadataService(new GitProcessService));
});

test('returns current branch for a normal repo', function () {
    $result = $this->action->handle($this->tmpDir);

    expect($result)->toBeInstanceOf(CurrentHeadResult::class)
        ->and($result->branch)->toBe('main')
        ->and($result->detached)->toBeFalse()
        ->and($result->sha)->toMatch('/^[0-9a-f]{40}$/');
});

test('returns the commit sha from rev-parse', function () {
    $expectedSha = trim((string) shell_exec('cd '.escapeshellarg($this->tmpDir).' && git rev-parse HEAD'));

    $result = $this->action->handle($this->tmpDir);

    expect($result->sha)->toBe($expectedSha);
});

test('detects detached HEAD', function () {
    $sha = trim((string) shell_exec('cd '.escapeshellarg($this->tmpDir).' && git rev-parse HEAD'));

    $this->runTestRepoCommand($this->tmpDir, "git checkout {$sha}");

    $result = $this->action->handle($this->tmpDir);

    expect($result->branch)->toBeNull()
        ->and($result->detached)->toBeTrue()
        ->and($result->sha)->toBe($sha);
});

test('returns sentinel result on git failure', function () {
    $nonGit = $this->createTempDirectory('rfa_current_head_nongit_');

    $result = $this->action->handle($nonGit);

    expect($result->branch)->toBeNull()
        ->and($result->sha)->toBe('')
        ->and($result->detached)->toBeFalse();
});

test('returns sentinel result when git process times out', function () {
    $timingOutService = new class(new GitProcessService) extends GitMetadataService
    {
        public function getCurrentBranch(string $directory): string
        {
            $process = new Process(['git', 'rev-parse', 'HEAD']);
            $process->setTimeout(30);

            throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
        }
    };

    $action = new GetCurrentHeadAction($timingOutService);

    $result = $action->handle($this->tmpDir);

    expect($result)->toBeInstanceOf(CurrentHeadResult::class)
        ->and($result->branch)->toBeNull()
        ->and($result->sha)->toBe('')
        ->and($result->detached)->toBeFalse();
});

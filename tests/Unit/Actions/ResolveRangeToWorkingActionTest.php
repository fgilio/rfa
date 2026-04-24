<?php

use App\Actions\ResolveRangeToWorkingAction;
use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_resolve_range_to_working_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/a.txt', "root\n");
    $this->commitTestRepo($this->tmpDir, 'root commit');
    $this->rootSha = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    File::put($this->tmpDir.'/b.txt', "child\n");
    $this->commitTestRepo($this->tmpDir, 'child commit');
    $this->childSha = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    $this->action = new ResolveRangeToWorkingAction(new GitMetadataService(new GitProcessService));
});

test('falls back to empty tree when from is parent of the root commit', function () {
    $target = $this->action->handle($this->tmpDir, $this->rootSha.'^');

    expect($target->from())->toBe(DiffTarget::EMPTY_TREE_HASH)
        ->and($target->to())->toBeNull();
});

test('passes through a normal <hash>^ from when the hash has a parent', function () {
    $target = $this->action->handle($this->tmpDir, $this->childSha.'^');

    expect($target->from())->toBe($this->childSha.'^')
        ->and($target->to())->toBeNull();
});

test('passes through a plain sha from unchanged', function () {
    $target = $this->action->handle($this->tmpDir, $this->rootSha);

    expect($target->from())->toBe($this->rootSha)
        ->and($target->to())->toBeNull()
        ->and($target->isWorkingDirectory())->toBeTrue();
});

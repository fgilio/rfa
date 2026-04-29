<?php

use App\Actions\IsSinceBaseViewAction;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_since_base_view_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/a.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'shared');
    $this->sharedSha = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    $this->action = new IsSinceBaseViewAction(new GitMetadataService(new GitProcessService));
});

test('returns true when diffFrom resolves to the merge-base SHA', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');
    File::put($this->tmpDir.'/a.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'feature commit');

    expect($this->action->handle($this->tmpDir, 'main', $this->sharedSha))->toBeTrue();
});

test('returns true when diffFrom is the merge-base parent caret form', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');
    File::put($this->tmpDir.'/a.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'first ahead');
    $first = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    // The picker emits `/rw/{oldestHash}^` when WT + commits are selected;
    // `{oldestHash}^` resolves to the merge-base for a contiguous since-base
    // selection, so the page should treat this view as "Since {base}".
    expect($this->action->handle($this->tmpDir, 'main', $first.'^'))->toBeTrue();
});

test('returns false when diffFrom is HEAD', function () {
    expect($this->action->handle($this->tmpDir, 'main', 'HEAD'))->toBeFalse();
});

test('returns false when base is null or empty', function () {
    expect($this->action->handle($this->tmpDir, null, $this->sharedSha))->toBeFalse()
        ->and($this->action->handle($this->tmpDir, '', $this->sharedSha))->toBeFalse();
});

test('returns false when base ref is missing', function () {
    expect($this->action->handle($this->tmpDir, 'origin/nonexistent', $this->sharedSha))->toBeFalse();
});

test('returns false when diffFrom resolves to a different SHA', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');
    File::put($this->tmpDir.'/a.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'first ahead');
    $first = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    // diffFrom is the feature commit itself, not the merge-base.
    expect($this->action->handle($this->tmpDir, 'main', $first))->toBeFalse();
});

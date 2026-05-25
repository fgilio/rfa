<?php

use App\Actions\LoadBranchExplorerSnapshotAction;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_branch_explorer_snapshot_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/base.txt', "base\n");
    $this->commitTestRepo($this->tmpDir, 'base');
    $this->runTestRepoCommand($this->tmpDir, 'git branch dev');
    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');

    $this->action = app(LoadBranchExplorerSnapshotAction::class);
});

test('snapshot keeps since-base count and commit rows on the same branch tip', function () {
    foreach (range(1, 2) as $i) {
        File::append($this->tmpDir.'/feature.txt', "line {$i}\n");
        $this->commitTestRepo($this->tmpDir, "ahead {$i}");
    }

    $before = $this->action->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: 'dev',
    );

    foreach (range(3, 14) as $i) {
        File::append($this->tmpDir.'/feature.txt', "line {$i}\n");
        $this->commitTestRepo($this->tmpDir, "ahead {$i}");
    }

    $after = $this->action->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: 'dev',
    );

    expect($before->branchBase['commitCount'])->toBe(2)
        ->and($before->commits[0]['message'])->toBe('ahead 2')
        ->and($after->branchBase['commitCount'])->toBe(14)
        ->and($after->commits)->toHaveCount(15)
        ->and($after->commits[0]['message'])->toBe('ahead 14')
        ->and($after->snapshotKey)->not->toBe($before->snapshotKey);
});

test('snapshot loads every since-base commit even when the range exceeds the page size', function () {
    foreach (range(1, 55) as $i) {
        File::append($this->tmpDir.'/feature.txt', "line {$i}\n");
        $this->commitTestRepo($this->tmpDir, sprintf('ahead %02d', $i));
    }

    $snapshot = $this->action->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: 'dev',
        pageSize: 50,
    );

    expect($snapshot->branchBase['commitCount'])->toBe(55)
        ->and($snapshot->commits)->toHaveCount(55)
        ->and($snapshot->hasMore)->toBeTrue();
});

test('snapshot uses lookahead for pagination instead of treating an exact page as more', function () {
    foreach (range(1, 49) as $i) {
        File::append($this->tmpDir.'/feature.txt', "line {$i}\n");
        $this->commitTestRepo($this->tmpDir, sprintf('ahead %02d', $i));
    }

    $exactPage = $this->action->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
        pageSize: 50,
    );

    File::append($this->tmpDir.'/feature.txt', "line 50\n");
    $this->commitTestRepo($this->tmpDir, 'ahead 50');

    $withLookahead = $this->action->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
        pageSize: 50,
    );

    expect($exactPage->commits)->toHaveCount(50)
        ->and($exactPage->hasMore)->toBeFalse()
        ->and($withLookahead->commits)->toHaveCount(50)
        ->and($withLookahead->hasMore)->toBeTrue();
});

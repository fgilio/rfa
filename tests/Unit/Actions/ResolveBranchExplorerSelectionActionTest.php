<?php

use App\Actions\LoadBranchExplorerSnapshotAction;
use App\Actions\ResolveBranchExplorerSelectionAction;
use App\Enums\BranchExplorerSelectionKind;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_branch_explorer_selection_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/base.txt', "base\n");
    $this->commitTestRepo($this->tmpDir, 'base');
    $this->baseSha = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));
    $this->runTestRepoCommand($this->tmpDir, 'git branch dev');
    $this->runTestRepoCommand($this->tmpDir, 'git checkout -b feature');

    foreach (['first', 'second', 'third'] as $message) {
        File::append($this->tmpDir.'/feature.txt', "{$message}\n");
        $this->commitTestRepo($this->tmpDir, $message);
    }

    $this->snapshotAction = app(LoadBranchExplorerSnapshotAction::class);
    $this->action = app(ResolveBranchExplorerSelectionAction::class);
});

test('exact since-base selection navigates using the merge-base sha', function () {
    $snapshot = $this->snapshotAction->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: 'dev',
    );

    $result = $this->action->handle(
        repoPath: $this->tmpDir,
        projectSlug: 'demo',
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: 'dev',
        selectedHashes: $snapshot->branchBase['hashesInRange'],
        workingTreeSelected: true,
        snapshotKey: $snapshot->snapshotKey,
        pageSize: 50,
        minimumCommitCount: count($snapshot->commits),
    );

    expect($result->kind)->toBe(BranchExplorerSelectionKind::Navigate)
        ->and($result->url)->toBe('/p/demo/rw/'.$this->baseSha);
});

test('stale snapshot key refreshes instead of applying against old rows', function () {
    $snapshot = $this->snapshotAction->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: 'dev',
    );

    File::append($this->tmpDir.'/feature.txt', "fourth\n");
    $this->commitTestRepo($this->tmpDir, 'fourth');

    $result = $this->action->handle(
        repoPath: $this->tmpDir,
        projectSlug: 'demo',
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: 'dev',
        selectedHashes: [$snapshot->commits[0]['hash']],
        workingTreeSelected: false,
        snapshotKey: $snapshot->snapshotKey,
        pageSize: 50,
        minimumCommitCount: count($snapshot->commits),
    );

    expect($result->kind)->toBe(BranchExplorerSelectionKind::Stale)
        ->and($result->message)->toContain('changed');
});

test('unknown selected hashes are treated as stale instead of silently dropped', function () {
    $snapshot = $this->snapshotAction->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
    );

    $result = $this->action->handle(
        repoPath: $this->tmpDir,
        projectSlug: 'demo',
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
        selectedHashes: [$snapshot->commits[0]['hash'], str_repeat('f', 40)],
        workingTreeSelected: false,
        snapshotKey: $snapshot->snapshotKey,
        pageSize: 50,
        minimumCommitCount: count($snapshot->commits),
    );

    expect($result->kind)->toBe(BranchExplorerSelectionKind::Stale);
});

test('working tree cannot be paired with commits from another displayed branch', function () {
    $snapshot = $this->snapshotAction->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'dev',
        currentBranch: 'feature',
        baseBranch: null,
    );

    $result = $this->action->handle(
        repoPath: $this->tmpDir,
        projectSlug: 'demo',
        selectedBranch: 'dev',
        currentBranch: 'feature',
        baseBranch: null,
        selectedHashes: [$snapshot->commits[0]['hash']],
        workingTreeSelected: true,
        snapshotKey: $snapshot->snapshotKey,
        pageSize: 50,
        minimumCommitCount: count($snapshot->commits),
    );

    expect($result->kind)->toBe(BranchExplorerSelectionKind::Error)
        ->and($result->message)->toContain('current branch');
});

test('working tree alone cannot be applied from another displayed branch', function () {
    $snapshot = $this->snapshotAction->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'dev',
        currentBranch: 'feature',
        baseBranch: null,
    );

    $result = $this->action->handle(
        repoPath: $this->tmpDir,
        projectSlug: 'demo',
        selectedBranch: 'dev',
        currentBranch: 'feature',
        baseBranch: null,
        selectedHashes: [],
        workingTreeSelected: true,
        snapshotKey: $snapshot->snapshotKey,
        pageSize: 50,
        minimumCommitCount: count($snapshot->commits),
    );

    expect($result->kind)->toBe(BranchExplorerSelectionKind::Error)
        ->and($result->message)->toContain('current branch');
});

test('non-contiguous commit selection returns an inline error result', function () {
    $snapshot = $this->snapshotAction->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
    );

    $result = $this->action->handle(
        repoPath: $this->tmpDir,
        projectSlug: 'demo',
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
        selectedHashes: [$snapshot->commits[0]['hash'], $snapshot->commits[2]['hash']],
        workingTreeSelected: false,
        snapshotKey: $snapshot->snapshotKey,
        pageSize: 50,
        minimumCommitCount: count($snapshot->commits),
    );

    expect($result->kind)->toBe(BranchExplorerSelectionKind::Error)
        ->and($result->message)->toContain('pick every commit');
});

test('working tree must be paired with the newest selected commit range', function () {
    $snapshot = $this->snapshotAction->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
    );

    $result = $this->action->handle(
        repoPath: $this->tmpDir,
        projectSlug: 'demo',
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
        selectedHashes: [$snapshot->commits[1]['hash']],
        workingTreeSelected: true,
        snapshotKey: $snapshot->snapshotKey,
        pageSize: 50,
        minimumCommitCount: count($snapshot->commits),
    );

    expect($result->kind)->toBe(BranchExplorerSelectionKind::Error)
        ->and($result->message)->toContain('newest commits');
});

test('contiguous commit selection navigates to the internal range route', function () {
    $snapshot = $this->snapshotAction->handle(
        repoPath: $this->tmpDir,
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
    );

    $result = $this->action->handle(
        repoPath: $this->tmpDir,
        projectSlug: 'demo',
        selectedBranch: 'feature',
        currentBranch: 'feature',
        baseBranch: null,
        selectedHashes: [$snapshot->commits[0]['hash'], $snapshot->commits[1]['hash']],
        workingTreeSelected: false,
        snapshotKey: $snapshot->snapshotKey,
        pageSize: 50,
        minimumCommitCount: count($snapshot->commits),
    );

    expect($result->kind)->toBe(BranchExplorerSelectionKind::Navigate)
        ->and($result->url)->toBe('/p/demo/'.$snapshot->commits[0]['hash'].'/'.$snapshot->commits[1]['hash'].'%5E');
});

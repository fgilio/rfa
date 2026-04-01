<?php

use App\Actions\GetBranchListAction;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_branchlist_test_');

    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'init');
});

test('returns branches as arrays with current branch identified', function () {
    $action = new GetBranchListAction(new GitMetadataService(new GitProcessService));
    $result = $action->handle($this->tmpDir);

    expect($result)->toHaveKeys(['local', 'remote', 'current'])
        ->and($result['current'])->toBe('main')
        ->and($result['local'])->toHaveCount(1)
        ->and($result['local'][0])->toHaveKeys(['name', 'isCurrent', 'isRemote', 'remote'])
        ->and($result['local'][0]['name'])->toBe('main')
        ->and($result['local'][0]['isCurrent'])->toBeTrue();
});

test('returns multiple branches sorted by git', function () {
    $this->runTestRepoCommand($this->tmpDir, [
        'git branch alpha',
        'git branch zeta',
    ]);

    $action = new GetBranchListAction(new GitMetadataService(new GitProcessService));
    $result = $action->handle($this->tmpDir);

    $names = array_column($result['local'], 'name');

    expect($names)->toContain('main')
        ->and($names)->toContain('alpha')
        ->and($names)->toContain('zeta');
});

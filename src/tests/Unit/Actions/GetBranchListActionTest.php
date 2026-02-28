<?php

use App\Actions\GetBranchListAction;
use App\Services\GitDiffService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_branchlist_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
        'git config commit.gpgsign false', // Disable GPG signing so test commits work without a key
    ]));

    File::put($this->tmpDir.'/file.txt', "ok\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('returns branches as arrays with current branch identified', function () {
    $action = new GetBranchListAction(new GitDiffService(new IgnoreService));
    $result = $action->handle($this->tmpDir);

    expect($result)->toHaveKeys(['local', 'remote', 'current'])
        ->and($result['current'])->toBe('main')
        ->and($result['local'])->toHaveCount(1)
        ->and($result['local'][0])->toHaveKeys(['name', 'isCurrent', 'isRemote', 'remote'])
        ->and($result['local'][0]['name'])->toBe('main')
        ->and($result['local'][0]['isCurrent'])->toBeTrue();
});

test('returns multiple branches sorted by git', function () {
    exec('cd '.escapeshellarg($this->tmpDir).' && git branch alpha && git branch zeta');

    $action = new GetBranchListAction(new GitDiffService(new IgnoreService));
    $result = $action->handle($this->tmpDir);

    $names = array_column($result['local'], 'name');

    expect($names)->toContain('main')
        ->and($names)->toContain('alpha')
        ->and($names)->toContain('zeta');
});

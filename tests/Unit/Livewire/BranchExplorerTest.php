<?php

use App\DTOs\BranchEntry;
use App\DTOs\CommitEntry;
use App\Services\GitMetadataService;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

function fakeGitMetadata(array $branches = ['local' => [], 'remote' => []], array $commits = []): GitMetadataService
{
    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('getBranches')->byDefault()->andReturn($branches);
    $mock->shouldReceive('getCommitLog')->byDefault()->andReturn($commits);
    app()->instance(GitMetadataService::class, $mock);

    return $mock;
}

test('mount stores the supplied props without dispatching git work', function () {
    $mock = fakeGitMetadata();
    $mock->shouldNotReceive('getBranches');
    $mock->shouldNotReceive('getCommitLog');

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
        'projectSlug' => 'demo',
        'hasRemote' => true,
        'selectionLabel' => 'Working tree',
        'selectionTitle' => 'Working tree changes',
    ]);

    expect($component->get('currentBranch'))->toBe('main')
        ->and($component->get('hasRemote'))->toBeTrue()
        ->and($component->get('branches'))->toBe(['local' => [], 'remote' => [], 'current' => '']);
});

test('loadBranches populates local + remote arrays from GetBranchListAction', function () {
    fakeGitMetadata([
        'local' => [
            new BranchEntry('main', true, false),
            new BranchEntry('feature', false, false),
        ],
        'remote' => [
            new BranchEntry('origin/main', false, true, 'origin'),
        ],
    ]);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
    ])->call('loadBranches');

    $branches = $component->get('branches');

    expect($branches['current'])->toBe('main')
        ->and($branches['local'])->toHaveCount(2)
        ->and($branches['local'][0]['name'])->toBe('main')
        ->and($branches['local'][0]['isCurrent'])->toBeTrue()
        ->and($branches['remote'])->toHaveCount(1)
        ->and($branches['remote'][0]['remote'])->toBe('origin');
});

test('loadCommits fills the page and sets hasMore when full', function () {
    $page = array_map(
        fn (int $i) => new CommitEntry("hash{$i}", "h{$i}", "msg {$i}", 'me', '1m ago', '2026-01-01'),
        range(1, 50),
    );

    fakeGitMetadata(commits: $page);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
    ])->call('loadCommits', 'main');

    expect($component->get('commits'))->toHaveCount(50)
        ->and($component->get('hasMore'))->toBeTrue();
});

test('loadCommits clears hasMore when fewer than the page size are returned', function () {
    fakeGitMetadata(commits: [
        new CommitEntry('h1', 'h1', 'msg', 'me', '1m', '2026-01-01'),
    ]);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
    ])->call('loadCommits', 'main');

    expect($component->get('commits'))->toHaveCount(1)
        ->and($component->get('hasMore'))->toBeFalse();
});

test('loadMore appends to commits without resetting them', function () {
    $first = array_map(
        fn (int $i) => new CommitEntry("a{$i}", "a{$i}", "first {$i}", 'me', '1m', '2026-01-01'),
        range(1, 50),
    );
    $second = [
        new CommitEntry('b1', 'b1', 'second 1', 'me', '1m', '2026-01-02'),
    ];

    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('getCommitLog')
        ->with('/tmp/repo', 50, 0, 'main')->once()->andReturn($first);
    $mock->shouldReceive('getCommitLog')
        ->with('/tmp/repo', 50, 50, 'main')->once()->andReturn($second);
    app()->instance(GitMetadataService::class, $mock);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
    ])
        ->call('loadCommits', 'main')
        ->call('loadMore', 'main');

    expect($component->get('commits'))->toHaveCount(51)
        ->and($component->get('commits')[0]['hash'])->toBe('a1')
        ->and($component->get('commits')[50]['hash'])->toBe('b1')
        ->and($component->get('hasMore'))->toBeFalse();
});

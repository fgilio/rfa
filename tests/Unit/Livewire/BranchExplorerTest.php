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
    $mock->shouldReceive('resolveRef')->byDefault()->andReturn('tip-sha');
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

test('loadSnapshot populates branches, commits, base state, and snapshot key together', function () {
    fakeGitMetadata([
        'local' => [
            new BranchEntry('main', true, false),
            new BranchEntry('feature', false, false),
        ],
        'remote' => [
            new BranchEntry('origin/main', false, true, 'origin'),
        ],
    ], [
        new CommitEntry('h1', 'h1', 'msg', 'me', '1m', '2026-01-01'),
    ]);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
    ])->call('loadSnapshot', 'main');

    $branches = $component->get('branches');
    $branchBase = $component->get('branchBase');

    expect($branches['current'])->toBe('main')
        ->and($branches['local'])->toHaveCount(2)
        ->and($branches['local'][0]['name'])->toBe('main')
        ->and($branches['local'][0]['isCurrent'])->toBeTrue()
        ->and($branches['remote'])->toHaveCount(1)
        ->and($branches['remote'][0]['remote'])->toBe('origin')
        ->and($component->get('commits'))->toHaveCount(1)
        ->and($branchBase['state'])->toBe('not_configured')
        ->and($component->get('snapshotKey'))->not->toBe('');
});

test('loadSnapshot fills the page and sets hasMore only when lookahead exists', function () {
    $page = array_map(
        fn (int $i) => new CommitEntry("hash{$i}", "h{$i}", "msg {$i}", 'me', '1m ago', '2026-01-01'),
        range(1, 51),
    );

    fakeGitMetadata(commits: $page);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
    ])->call('loadSnapshot', 'main');

    expect($component->get('commits'))->toHaveCount(50)
        ->and($component->get('hasMore'))->toBeTrue();
});

test('loadSnapshot clears hasMore when exactly the page size is returned', function () {
    $page = array_map(
        fn (int $i) => new CommitEntry("hash{$i}", "h{$i}", "msg {$i}", 'me', '1m ago', '2026-01-01'),
        range(1, 50),
    );

    fakeGitMetadata(commits: $page);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
    ])->call('loadSnapshot', 'main');

    expect($component->get('commits'))->toHaveCount(50)
        ->and($component->get('hasMore'))->toBeFalse();
});

test('loadMore reloads a larger coherent snapshot', function () {
    $commits = array_map(
        fn (int $i) => new CommitEntry("a{$i}", "a{$i}", "commit {$i}", 'me', '1m', '2026-01-01'),
        range(1, 60),
    );

    fakeGitMetadata(commits: $commits);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
    ])->call('loadSnapshot', 'main');

    $snapshotKey = $component->get('snapshotKey');

    $component->call('loadMore', 'main', $snapshotKey);

    expect($component->get('commits'))->toHaveCount(60)
        ->and($component->get('commits')[0]['hash'])->toBe('a1')
        ->and($component->get('commits')[59]['hash'])->toBe('a60')
        ->and($component->get('hasMore'))->toBeFalse();
});

test('applySelection redirects through the server resolver for a selected commit', function () {
    fakeGitMetadata(commits: [
        new CommitEntry('h1', 'h1', 'tip commit', 'me', '1m', '2026-01-01'),
        new CommitEntry('h2', 'h2', 'older commit', 'me', '2m', '2026-01-01'),
    ]);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
        'projectSlug' => 'demo',
    ])->call('loadSnapshot', 'main');

    $component
        ->call('applySelection', 'main', ['h1'], false, $component->get('snapshotKey'))
        ->assertRedirect('/p/demo/c/h1');
});

test('applySelection dispatches an inline error for non-contiguous commits', function () {
    fakeGitMetadata(commits: [
        new CommitEntry('h1', 'h1', 'tip commit', 'me', '1m', '2026-01-01'),
        new CommitEntry('h2', 'h2', 'middle commit', 'me', '2m', '2026-01-01'),
        new CommitEntry('h3', 'h3', 'old commit', 'me', '3m', '2026-01-01'),
    ]);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
        'projectSlug' => 'demo',
    ])->call('loadSnapshot', 'main');

    $component
        ->call('applySelection', 'main', ['h1', 'h3'], false, $component->get('snapshotKey'))
        ->assertDispatched('branch-explorer-selection-error', function (string $event, array $params): bool {
            return str_contains($params['message'] ?? '', 'pick every commit');
        });
});

test('applySelection refreshes and dispatches stale when the snapshot key changed', function () {
    fakeGitMetadata(commits: [
        new CommitEntry('h1', 'h1', 'tip commit', 'me', '1m', '2026-01-01'),
    ]);

    $component = Livewire::test('branch-explorer', [
        'repoPath' => '/tmp/repo',
        'currentBranch' => 'main',
        'projectSlug' => 'demo',
    ])->call('loadSnapshot', 'main');

    $component
        ->call('applySelection', 'main', ['h1'], false, 'stale-snapshot-key')
        ->assertDispatched('branch-explorer-selection-stale', function (string $event, array $params): bool {
            return str_contains($params['message'] ?? '', 'changed');
        });
});

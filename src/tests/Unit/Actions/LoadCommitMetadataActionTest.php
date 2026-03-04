<?php

use App\Actions\LoadCommitMetadataAction;
use App\DTOs\CommitEntry;
use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;

uses(Tests\TestCase::class);

test('returns metadata for valid commit', function () {
    $commit = new CommitEntry(
        hash: 'abc123def456abc123def456abc123def456abc1',
        shortHash: 'abc123d',
        message: 'Fix bug',
        author: 'Test User',
        relativeDate: '2 hours ago',
        date: '2026-03-04T10:00:00+00:00',
    );

    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('getCommitLog')
        ->once()
        ->andReturn([$commit]);
    $mock->shouldReceive('getChildCommit')
        ->once()
        ->andReturn('def456');

    $action = new LoadCommitMetadataAction($mock);
    $result = $action->handle('/tmp/repo', 'abc123', 'parent1');

    expect($result)->toBe([
        'shortHash' => 'abc123d',
        'message' => 'Fix bug',
        'author' => 'Test User',
        'prevHash' => 'parent1',
        'nextHash' => 'def456',
    ]);
});

test('handles root commit with empty tree parent', function () {
    $commit = new CommitEntry(
        hash: 'abc123def456abc123def456abc123def456abc1',
        shortHash: 'abc123d',
        message: 'Initial commit',
        author: 'Test User',
        relativeDate: '1 day ago',
        date: '2026-03-03T10:00:00+00:00',
    );

    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('getCommitLog')->andReturn([$commit]);
    $mock->shouldReceive('getChildCommit')->andReturn('child1');

    $action = new LoadCommitMetadataAction($mock);
    $result = $action->handle('/tmp/repo', 'abc123', DiffTarget::EMPTY_TREE_HASH);

    expect($result['prevHash'])->toBeNull();
    expect($result['nextHash'])->toBe('child1');
});

test('includes child commit hash', function () {
    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('getCommitLog')->andReturn([]);
    $mock->shouldReceive('getChildCommit')->andReturn(null);

    $action = new LoadCommitMetadataAction($mock);
    $result = $action->handle('/tmp/repo', 'abc123', 'parent1');

    expect($result['nextHash'])->toBeNull();
    expect($result['shortHash'])->toBe('abc123');
});

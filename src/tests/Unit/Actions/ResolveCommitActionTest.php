<?php

use App\Actions\ResolveCommitAction;
use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;

test('returns diff target for valid ref with parent', function () {
    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('resolveRef')
        ->with('/tmp/repo', 'HEAD~1')
        ->once()
        ->andReturn('abc123');
    $mock->shouldReceive('getCommitParents')
        ->with('/tmp/repo', 'abc123')
        ->once()
        ->andReturn(['parent1']);

    $action = new ResolveCommitAction($mock);
    $result = $action->handle('/tmp/repo', 'HEAD~1');

    expect($result)->toBeInstanceOf(DiffTarget::class)
        ->and($result->from())->toBe('parent1')
        ->and($result->to())->toBe('abc123');
});

test('returns null for unresolvable ref', function () {
    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('resolveRef')->once()->andReturn(null);
    $mock->shouldNotReceive('getCommitParents');

    $action = new ResolveCommitAction($mock);

    expect($action->handle('/tmp/repo', 'nonexistent'))->toBeNull();
});

test('handles root commit with no parents', function () {
    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('resolveRef')->andReturn('root-hash');
    $mock->shouldReceive('getCommitParents')->andReturn([]);

    $action = new ResolveCommitAction($mock);
    $result = $action->handle('/tmp/repo', 'root-hash');

    expect($result->from())->toBe(DiffTarget::EMPTY_TREE_HASH)
        ->and($result->to())->toBe('root-hash');
});

test('uses first parent for merge commits', function () {
    $mock = Mockery::mock(GitMetadataService::class);
    $mock->shouldReceive('resolveRef')->andReturn('merge-hash');
    $mock->shouldReceive('getCommitParents')->andReturn(['parent1', 'parent2']);

    $action = new ResolveCommitAction($mock);
    $result = $action->handle('/tmp/repo', 'merge-hash');

    expect($result->from())->toBe('parent1');
});

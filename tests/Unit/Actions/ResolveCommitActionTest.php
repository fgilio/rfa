<?php

use App\Actions\ResolveCommitAction;
use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;
use Tests\TestCase;

uses(TestCase::class);

test('returns null when ref does not resolve', function () {
    $git = Mockery::mock(GitMetadataService::class);
    $git->shouldReceive('resolveRef')->once()->andReturn(null);
    $git->shouldNotReceive('getCommitParents');

    expect((new ResolveCommitAction($git))->handle('/tmp/repo', 'nope'))->toBeNull();
});

test('uses parent hash as from for non-root commit', function () {
    $git = Mockery::mock(GitMetadataService::class);
    $git->shouldReceive('resolveRef')->with('/tmp/repo', 'HEAD')->once()->andReturn('abc');
    $git->shouldReceive('getCommitParents')->with('/tmp/repo', 'abc')->once()->andReturn(['parent']);

    $target = (new ResolveCommitAction($git))->handle('/tmp/repo', 'HEAD');

    expect($target)->toBeInstanceOf(DiffTarget::class)
        ->and($target->from())->toBe('parent')
        ->and($target->to())->toBe('abc');
});

test('falls back to empty tree hash for root commit', function () {
    $git = Mockery::mock(GitMetadataService::class);
    $git->shouldReceive('resolveRef')->andReturn('root');
    $git->shouldReceive('getCommitParents')->andReturn([]);

    $target = (new ResolveCommitAction($git))->handle('/tmp/repo', 'root');

    expect($target->from())->toBe(DiffTarget::EMPTY_TREE_HASH)
        ->and($target->to())->toBe('root');
});

test('uses first parent for merge commits', function () {
    $git = Mockery::mock(GitMetadataService::class);
    $git->shouldReceive('resolveRef')->andReturn('merge');
    $git->shouldReceive('getCommitParents')->andReturn(['p1', 'p2']);

    $target = (new ResolveCommitAction($git))->handle('/tmp/repo', 'merge');

    expect($target->from())->toBe('p1');
});

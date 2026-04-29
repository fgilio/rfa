<?php

use App\Actions\GetFileCopyContentAction;
use App\DTOs\DiffTarget;
use App\Enums\GitRef;
use App\Services\GitDiffService;
use App\Services\GitMetadataService;
use Tests\TestCase;

uses(TestCase::class);

function makeCopyAction(GitDiffService $diff, GitMetadataService $meta): GetFileCopyContentAction
{
    return new GetFileCopyContentAction($diff, $meta);
}

test('diff kind delegates to GitDiffService::getFileDiff with the target', function () {
    $target = DiffTarget::range('abc', 'def');

    $diff = Mockery::mock(GitDiffService::class);
    $diff->shouldReceive('getFileDiff')
        ->with('/tmp/repo', 'src/foo.php', true, null, 3, Mockery::on(fn ($t) => $t === $target), null)
        ->once()
        ->andReturn('diff body');

    $meta = Mockery::mock(GitMetadataService::class);
    $meta->shouldNotReceive('getFileContent');

    $result = makeCopyAction($diff, $meta)
        ->handle('diff', '/tmp/repo', 'src/foo.php', true, $target);

    expect($result)->toBe('diff body');
});

test('original kind reads the from-side file content', function () {
    $target = DiffTarget::range('abc', 'def');

    $meta = Mockery::mock(GitMetadataService::class);
    $meta->shouldReceive('getFileContent')
        ->with('/tmp/repo', 'src/foo.php', 'abc')
        ->once()
        ->andReturn('original body');

    $diff = Mockery::mock(GitDiffService::class);
    $diff->shouldNotReceive('getFileDiff');

    $result = makeCopyAction($diff, $meta)
        ->handle('original', '/tmp/repo', 'src/foo.php', false, $target);

    expect($result)->toBe('original body');
});

test('original kind prefers oldPath when provided (renames)', function () {
    $target = DiffTarget::range('abc', 'def');

    $meta = Mockery::mock(GitMetadataService::class);
    $meta->shouldReceive('getFileContent')
        ->with('/tmp/repo', 'src/old.php', 'abc')
        ->once()
        ->andReturn('original body');

    $diff = Mockery::mock(GitDiffService::class);

    $result = makeCopyAction($diff, $meta)
        ->handle('original', '/tmp/repo', 'src/new.php', false, $target, 'src/old.php');

    expect($result)->toBe('original body');
});

test('new kind reads the to-side file content', function () {
    $target = DiffTarget::range('abc', 'def');

    $meta = Mockery::mock(GitMetadataService::class);
    $meta->shouldReceive('getFileContent')
        ->with('/tmp/repo', 'src/foo.php', 'def')
        ->once()
        ->andReturn('new body');

    $diff = Mockery::mock(GitDiffService::class);

    $result = makeCopyAction($diff, $meta)
        ->handle('new', '/tmp/repo', 'src/foo.php', false, $target);

    expect($result)->toBe('new body');
});

test('new kind falls back to working ref when target is working directory', function () {
    $target = DiffTarget::workingDirectory();

    $meta = Mockery::mock(GitMetadataService::class);
    $meta->shouldReceive('getFileContent')
        ->with('/tmp/repo', 'src/foo.php', GitRef::Working->value)
        ->once()
        ->andReturn('working body');

    $diff = Mockery::mock(GitDiffService::class);

    $result = makeCopyAction($diff, $meta)
        ->handle('new', '/tmp/repo', 'src/foo.php', false, $target);

    expect($result)->toBe('working body');
});

test('unknown kind returns null without calling either service', function () {
    $diff = Mockery::mock(GitDiffService::class);
    $diff->shouldNotReceive('getFileDiff');

    $meta = Mockery::mock(GitMetadataService::class);
    $meta->shouldNotReceive('getFileContent');

    $result = makeCopyAction($diff, $meta)
        ->handle('bogus', '/tmp/repo', 'src/foo.php', false, DiffTarget::workingDirectory());

    expect($result)->toBeNull();
});

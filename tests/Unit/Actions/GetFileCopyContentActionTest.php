<?php

use App\Actions\GetFileCopyContentAction;
use App\DTOs\DiffTarget;
use App\DTOs\FileSourceSpec;
use App\DTOs\SourceText;
use App\Enums\GitRef;
use App\Services\FileSourceService;
use App\Services\GitDiffService;
use Tests\TestCase;

uses(TestCase::class);

function makeCopyAction(GitDiffService $diff, FileSourceService $fileSource): GetFileCopyContentAction
{
    return new GetFileCopyContentAction($diff, $fileSource);
}

/** Match a git-type FileSourceSpec by ref and path. */
function gitSourceIs(string $ref, string $path): Closure
{
    return fn (FileSourceSpec $s): bool => $s->type === FileSourceSpec::TYPE_GIT && $s->ref === $ref && $s->path === $path;
}

test('diff kind delegates to GitDiffService::getFileDiff with the target', function () {
    $target = DiffTarget::range('abc', 'def');

    $diff = Mockery::mock(GitDiffService::class);
    $diff->shouldReceive('getFileDiff')
        // contextLines is null here: the caller omits it so getFileDiff resolves
        // the configured default itself, rather than passing a hardcoded 3.
        ->with('/tmp/repo', 'src/foo.php', true, null, null, Mockery::on(fn ($t) => $t === $target), null, false)
        ->once()
        ->andReturn('diff body');

    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldNotReceive('fetch');

    $result = makeCopyAction($diff, $fileSource)
        ->handle('diff', '/tmp/repo', 'src/foo.php', true, $target);

    expect($result)->toBe('diff body');
});

test('original kind fetches the from-side source', function () {
    $target = DiffTarget::range('abc', 'def');

    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldReceive('fetch')
        ->with('/tmp/repo', Mockery::on(gitSourceIs('abc', 'src/foo.php')))
        ->once()
        ->andReturn(SourceText::loaded(FileSourceSpec::git('abc', 'src/foo.php'), 'original body'));

    $diff = Mockery::mock(GitDiffService::class);
    $diff->shouldNotReceive('getFileDiff');

    $result = makeCopyAction($diff, $fileSource)
        ->handle('original', '/tmp/repo', 'src/foo.php', false, $target);

    expect($result)->toBe('original body');
});

test('original kind prefers oldPath when provided (renames)', function () {
    $target = DiffTarget::range('abc', 'def');

    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldReceive('fetch')
        ->with('/tmp/repo', Mockery::on(gitSourceIs('abc', 'src/old.php')))
        ->once()
        ->andReturn(SourceText::loaded(FileSourceSpec::git('abc', 'src/old.php'), 'original body'));

    $diff = Mockery::mock(GitDiffService::class);

    $result = makeCopyAction($diff, $fileSource)
        ->handle('original', '/tmp/repo', 'src/new.php', false, $target, 'src/old.php');

    expect($result)->toBe('original body');
});

test('original kind returns null for an added file without fetching', function () {
    $fileSource = Mockery::mock(FileSourceService::class);
    // An added file has no original side, so the source resolves to none and
    // the action short-circuits before reading any content.
    $fileSource->shouldNotReceive('fetch');

    $diff = Mockery::mock(GitDiffService::class);
    $diff->shouldNotReceive('getFileDiff');

    $result = makeCopyAction($diff, $fileSource)
        ->handle('original', '/tmp/repo', 'src/foo.php', false, DiffTarget::range('abc', 'def'), status: 'added');

    expect($result)->toBeNull();
});

test('new kind returns null for a deleted file without fetching', function () {
    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldNotReceive('fetch');

    $diff = Mockery::mock(GitDiffService::class);
    $diff->shouldNotReceive('getFileDiff');

    $result = makeCopyAction($diff, $fileSource)
        ->handle('new', '/tmp/repo', 'src/foo.php', false, DiffTarget::range('abc', 'def'), status: 'deleted');

    expect($result)->toBeNull();
});

test('new kind fetches the to-side source', function () {
    $target = DiffTarget::range('abc', 'def');

    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldReceive('fetch')
        ->with('/tmp/repo', Mockery::on(gitSourceIs('def', 'src/foo.php')))
        ->once()
        ->andReturn(SourceText::loaded(FileSourceSpec::git('def', 'src/foo.php'), 'new body'));

    $diff = Mockery::mock(GitDiffService::class);

    $result = makeCopyAction($diff, $fileSource)
        ->handle('new', '/tmp/repo', 'src/foo.php', false, $target);

    expect($result)->toBe('new body');
});

test('new kind falls back to the working ref when target is the working directory', function () {
    $target = DiffTarget::workingDirectory();

    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldReceive('fetch')
        ->with('/tmp/repo', Mockery::on(gitSourceIs(GitRef::Working->value, 'src/foo.php')))
        ->once()
        ->andReturn(SourceText::loaded(FileSourceSpec::working('src/foo.php'), 'working body'));

    $diff = Mockery::mock(GitDiffService::class);

    $result = makeCopyAction($diff, $fileSource)
        ->handle('new', '/tmp/repo', 'src/foo.php', false, $target);

    expect($result)->toBe('working body');
});

test('new kind reads an external file from its absolute path', function () {
    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldReceive('fetch')
        ->with('/tmp/repo', Mockery::on(
            fn (FileSourceSpec $s): bool => $s->type === FileSourceSpec::TYPE_ABSOLUTE && $s->absolutePath === '/outside/spec.md'
        ))
        ->once()
        ->andReturn(SourceText::loaded(FileSourceSpec::absolute('/outside/spec.md'), 'external body'));

    $diff = Mockery::mock(GitDiffService::class);

    $result = makeCopyAction($diff, $fileSource)
        ->handle('new', '/tmp/repo', '__external__/spec.md', false, DiffTarget::workingDirectory(), status: 'added', isExternal: true, externalAbsolutePath: '/outside/spec.md');

    expect($result)->toBe('external body');
});

test('returns null when the source fetch is not loaded', function () {
    $target = DiffTarget::range('abc', 'def');

    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldReceive('fetch')
        ->once()
        ->andReturn(SourceText::missing(FileSourceSpec::git('def', 'src/foo.php')));

    $diff = Mockery::mock(GitDiffService::class);

    $result = makeCopyAction($diff, $fileSource)
        ->handle('new', '/tmp/repo', 'src/foo.php', false, $target);

    expect($result)->toBeNull();
});

test('unknown kind returns null without calling either service', function () {
    $diff = Mockery::mock(GitDiffService::class);
    $diff->shouldNotReceive('getFileDiff');

    $fileSource = Mockery::mock(FileSourceService::class);
    $fileSource->shouldNotReceive('fetch');

    $result = makeCopyAction($diff, $fileSource)
        ->handle('bogus', '/tmp/repo', 'src/foo.php', false, DiffTarget::workingDirectory());

    expect($result)->toBeNull();
});

<?php

use App\DTOs\FileSourceSpec;
use App\Enums\GitRef;
use App\Services\GitFileContentService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = new GitFileContentService(new GitProcessService);

    $this->tmpDir = $this->createTempDirectory('rfa_gitfile_content_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/hello.php', "<?php\necho 'one';\n");
    $this->commitTestRepo($this->tmpDir, 'first');
    $this->firstCommit = trim(shell_exec('git -C '.escapeshellarg($this->tmpDir).' rev-parse HEAD'));

    File::put($this->tmpDir.'/hello.php', "<?php\necho 'two';\n");
    $this->commitTestRepo($this->tmpDir, 'second');
    $this->secondCommit = trim(shell_exec('git -C '.escapeshellarg($this->tmpDir).' rev-parse HEAD'));
});

test('hashAt returns the same value for identical file content across refs', function () {
    File::put($this->tmpDir.'/stable.php', "<?php\nreturn 1;\n");
    $this->commitTestRepo($this->tmpDir, 'add stable');
    $stableCommit = trim(shell_exec('git -C '.escapeshellarg($this->tmpDir).' rev-parse HEAD'));

    File::put($this->tmpDir.'/unrelated.php', "<?php\nreturn 2;\n");
    $this->commitTestRepo($this->tmpDir, 'add unrelated');
    $laterCommit = trim(shell_exec('git -C '.escapeshellarg($this->tmpDir).' rev-parse HEAD'));

    $first = $this->service->hashAt($this->tmpDir, $stableCommit, 'stable.php');
    $second = $this->service->hashAt($this->tmpDir, $laterCommit, 'stable.php');

    expect($first)->not->toBeNull();
    expect($first)->toBe($second);
});

test('hashAt returns different values when content changed', function () {
    $first = $this->service->hashAt($this->tmpDir, $this->firstCommit, 'hello.php');
    $second = $this->service->hashAt($this->tmpDir, $this->secondCommit, 'hello.php');

    expect($first)->not->toBe($second);
});

test('hashAt returns null for a file that does not exist at the given ref', function () {
    $result = $this->service->hashAt($this->tmpDir, $this->firstCommit, 'never-existed.php');

    expect($result)->toBeNull();
});

test('hashAt reads the working copy for the GitRef::Working sentinel', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'uncommitted';\n");

    $workingHash = $this->service->hashAt($this->tmpDir, GitRef::Working->value, 'hello.php');
    $headHash = $this->service->hashAt($this->tmpDir, $this->secondCommit, 'hello.php');

    expect($workingHash)->not->toBeNull();
    expect($workingHash)->not->toBe($headHash);
});

test('hashAt returns null when working copy file is missing', function () {
    expect($this->service->hashAt($this->tmpDir, GitRef::Working->value, 'missing.php'))->toBeNull();
});

test('contentAt hashes working tree symlink identity instead of followed content', function () {
    $outside = $this->createTempDirectory('rfa_gitfile_content_outside_');
    File::put($outside.'/secret.php', "<?php\necho 'outside';\n");
    symlink($outside.'/secret.php', $this->tmpDir.'/escape.php');

    expect($this->service->contentAt($this->tmpDir, GitRef::Working->value, 'escape.php'))
        ->toBe($outside.'/secret.php')
        ->and($this->service->hashAt($this->tmpDir, GitRef::Working->value, 'escape.php'))
        ->toBe(hash('xxh128', $outside.'/secret.php'));
});

test('hashAt memoizes repeated lookups for the same (repo, ref, path)', function () {
    $gitProcess = Mockery::mock(GitProcessService::class);
    $gitProcess->shouldReceive('run')->once()->andReturn("stable content\n");

    $service = new GitFileContentService($gitProcess);

    $first = $service->hashAt($this->tmpDir, $this->firstCommit, 'hello.php');
    $second = $service->hashAt($this->tmpDir, $this->firstCommit, 'hello.php');
    $third = $service->hashAt($this->tmpDir, $this->firstCommit, 'hello.php');

    expect($second)->toBe($first);
    expect($third)->toBe($first);
});

test('GitFileContentService is a shared singleton in the container', function () {
    $first = app(GitFileContentService::class);
    $second = app(GitFileContentService::class);

    expect($first)->toBe($second);
});

test('flushCache forces a second git lookup for the same (repo, ref, path)', function () {
    $gitProcess = Mockery::mock(GitProcessService::class);
    $gitProcess->shouldReceive('run')->twice()->andReturn("stable content\n");

    $service = new GitFileContentService($gitProcess);

    $service->hashAt($this->tmpDir, $this->firstCommit, 'hello.php');
    $service->flushCache();
    $service->hashAt($this->tmpDir, $this->firstCommit, 'hello.php');
});

test('byteSizeAt reports sizes without reading content for working, index, and commit refs', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'working';\n");

    expect($this->service->byteSizeAt($this->tmpDir, GitRef::Working->value, 'hello.php'))
        ->toBe(strlen("<?php\necho 'working';\n"))
        ->and($this->service->byteSizeAt($this->tmpDir, $this->firstCommit, 'hello.php'))
        ->toBe(strlen("<?php\necho 'one';\n"))
        ->and($this->service->byteSizeAt($this->tmpDir, $this->secondCommit, 'hello.php'))
        ->toBe(strlen("<?php\necho 'two';\n"));
});

test('byteSizeAt returns null for files missing at the ref', function () {
    expect($this->service->byteSizeAt($this->tmpDir, $this->firstCommit, 'missing.php'))->toBeNull()
        ->and($this->service->byteSizeAt($this->tmpDir, GitRef::Working->value, 'missing.php'))->toBeNull();
});

test('byteSizeAt matches contentAt for working symlink leaves', function () {
    symlink('hello.php', $this->tmpDir.'/link.php');

    $content = $this->service->contentAt($this->tmpDir, GitRef::Working->value, 'link.php');

    expect($content)->toBe('hello.php')
        ->and($this->service->byteSizeAt($this->tmpDir, GitRef::Working->value, 'link.php'))
        ->toBe(strlen($content));
});

test('byteSizeAtAbsolute reports the on-disk size or null when missing', function () {
    expect($this->service->byteSizeAtAbsolute($this->tmpDir.'/hello.php'))
        ->toBe(strlen("<?php\necho 'two';\n"))
        ->and($this->service->byteSizeAtAbsolute($this->tmpDir.'/nope.php'))->toBeNull();
});

// -- spec-aware readers --

test('hashForSource dispatches a git source to hashAt', function () {
    $source = FileSourceSpec::git($this->firstCommit, 'hello.php');

    expect($this->service->hashForSource($this->tmpDir, $source))
        ->toBe($this->service->hashAt($this->tmpDir, $this->firstCommit, 'hello.php'));
});

test('hashForSource dispatches an absolute source to hashAtAbsolute', function () {
    $source = FileSourceSpec::absolute($this->tmpDir.'/hello.php');

    expect($this->service->hashForSource($this->tmpDir, $source))
        ->toBe($this->service->hashAtAbsolute($this->tmpDir.'/hello.php'));
});

test('hashForSource returns null for a none source', function () {
    expect($this->service->hashForSource($this->tmpDir, FileSourceSpec::none()))->toBeNull();
});

test('contentForSource dispatches by source type', function () {
    expect($this->service->contentForSource($this->tmpDir, FileSourceSpec::git($this->firstCommit, 'hello.php')))
        ->toBe("<?php\necho 'one';\n")
        ->and($this->service->contentForSource($this->tmpDir, FileSourceSpec::absolute($this->tmpDir.'/hello.php')))
        ->toBe("<?php\necho 'two';\n")
        ->and($this->service->contentForSource($this->tmpDir, FileSourceSpec::none()))
        ->toBeNull();
});

test('byteSizeForSource dispatches by source type', function () {
    expect($this->service->byteSizeForSource($this->tmpDir, FileSourceSpec::git($this->firstCommit, 'hello.php')))
        ->toBe(strlen("<?php\necho 'one';\n"))
        ->and($this->service->byteSizeForSource($this->tmpDir, FileSourceSpec::absolute($this->tmpDir.'/hello.php')))
        ->toBe(strlen("<?php\necho 'two';\n"))
        ->and($this->service->byteSizeForSource($this->tmpDir, FileSourceSpec::none()))
        ->toBeNull();
});

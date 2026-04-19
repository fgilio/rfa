<?php

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

test('hashAt reads the working copy for the WORKING_REF sentinel', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'uncommitted';\n");

    $workingHash = $this->service->hashAt($this->tmpDir, GitFileContentService::WORKING_REF, 'hello.php');
    $headHash = $this->service->hashAt($this->tmpDir, $this->secondCommit, 'hello.php');

    expect($workingHash)->not->toBeNull();
    expect($workingHash)->not->toBe($headHash);
});

test('hashAt returns null when working copy file is missing', function () {
    expect($this->service->hashAt($this->tmpDir, GitFileContentService::WORKING_REF, 'missing.php'))->toBeNull();
});

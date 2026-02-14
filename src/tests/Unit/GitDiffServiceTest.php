<?php

use App\Services\GitDiffService;
use App\Services\IgnoreService;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->service = new GitDiffService(new IgnoreService);

    $ref = new ReflectionClass($this->service);

    $this->isBinary = $ref->getMethod('isBinary');
    $this->isBinary->setAccessible(true);

    $this->tmpDir = sys_get_temp_dir().'/rfa_git_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

// -- isBinary tests --

test('isBinary detects null bytes', function () {
    $path = $this->tmpDir.'/binary.bin';
    $content = $this->faker->sentence()."\0".$this->faker->sentence();
    File::put($path, $content);

    expect($this->isBinary->invoke($this->service, $path))->toBeTrue();
});

test('isBinary returns false for plain text', function () {
    $path = $this->tmpDir.'/text.txt';
    File::put($path, $this->faker->paragraphs(3, true));

    expect($this->isBinary->invoke($this->service, $path))->toBeFalse();
});

// -- Helper: init a git repo in tmpDir --

function initRepo(string $dir): void
{
    exec(implode(' && ', [
        'cd '.escapeshellarg($dir),
        'git init',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
    ]));
}

function commitAll(string $dir, string $message = 'commit'): void
{
    exec(implode(' && ', [
        'cd '.escapeshellarg($dir),
        'git add -A',
        'git commit -m '.escapeshellarg($message),
    ]));
}

// -- getFileList tests --

test('getFileList returns modified file with correct status', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "line1\nchanged\nline3\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->path)->toBe('hello.txt');
    expect($entries[0]->status)->toBe('modified');
    expect($entries[0]->additions)->toBeGreaterThan(0);
    expect($entries[0]->isUntracked)->toBeFalse();
});

test('getFileList returns added file for untracked', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/tracked.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/newfile.txt', "hello\nworld\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    $entry = $entries[0];
    expect($entry->path)->toBe('newfile.txt');
    expect($entry->status)->toBe('added');
    expect($entry->isUntracked)->toBeTrue();
    expect($entry->additions)->toBe(2);
});

test('getFileList returns deleted file', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/doomed.txt', "bye\n");
    commitAll($this->tmpDir, 'initial');

    File::delete($this->tmpDir.'/doomed.txt');

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->path)->toBe('doomed.txt');
    expect($entries[0]->status)->toBe('deleted');
});

test('getFileList returns renamed file with oldPath', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/old_name.txt', "content\n");
    commitAll($this->tmpDir, 'initial');

    exec('cd '.escapeshellarg($this->tmpDir).' && git mv old_name.txt new_name.txt');

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->status)->toBe('renamed');
    expect($entries[0]->path)->toBe('new_name.txt');
    expect($entries[0]->oldPath)->toBe('old_name.txt');
});

test('getFileList detects binary files', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/binary.bin', "hello\0world");

    $entries = $this->service->getFileList($this->tmpDir);

    $binary = collect($entries)->firstWhere('path', 'binary.bin');
    expect($binary)->not->toBeNull();
    expect($binary->isBinary)->toBeTrue();
});

test('getFileList excludes rfaignore patterns', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/keep.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/.rfaignore', "*.log\n");
    File::put($this->tmpDir.'/debug.log', "should not appear\n");
    File::put($this->tmpDir.'/visible.txt', "should appear\n");

    $entries = $this->service->getFileList($this->tmpDir);
    $paths = collect($entries)->pluck('path')->all();

    expect($paths)->toContain('visible.txt');
    expect($paths)->not->toContain('debug.log');
});

test('getFileList returns empty for clean repo', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toBeEmpty();
});

// -- getFileDiff tests --

test('getFileDiff returns raw diff for tracked file', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'hello.txt');

    expect($diff)->toStartWith('diff --git');
    expect($diff)->toContain('+line2');
});

test('getFileDiff returns synthetic diff for untracked file', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/newfile.txt', "hello\nworld\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'newfile.txt', isUntracked: true);

    expect($diff)->toContain('new file mode');
    expect($diff)->toContain('+hello');
});

test('getFileDiff returns null when diff exceeds max bytes', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/big.txt', "small\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/big.txt', str_repeat("long line of content\n", 500));

    $diff = $this->service->getFileDiff($this->tmpDir, 'big.txt', maxBytes: 100);

    expect($diff)->toBeNull();
});

test('getFileDiff handles binary untracked file', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/image.bin', "png\0data");

    $diff = $this->service->getFileDiff($this->tmpDir, 'image.bin', isUntracked: true);

    expect($diff)->toContain('Binary files');
});

test('getFileDiff returns empty string for missing untracked file', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    $diff = $this->service->getFileDiff($this->tmpDir, 'gone.txt', isUntracked: true);

    expect($diff)->toBe('');
});

test('getFileDiff untracked file with trailing newline has correct line count', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/newfile.txt', "line1\nline2\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'newfile.txt', isUntracked: true);

    // Should be +1,2 (2 real lines), not +1,3
    expect($diff)->toContain('@@ -0,0 +1,2 @@');
    expect($diff)->toContain('+line1');
    expect($diff)->toContain('+line2');
});

test('getFileDiff handles empty untracked file', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/empty.txt', '');

    $diff = $this->service->getFileDiff($this->tmpDir, 'empty.txt', isUntracked: true);

    expect($diff)->toContain('new file mode');
    expect($diff)->not->toContain('@@ ');
});

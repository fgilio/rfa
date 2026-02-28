<?php

use App\Exceptions\GitCommandException;
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
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
        'git config commit.gpgsign false',
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

test('getFileList excludes untracked files matching globalGitignorePath', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/tracked.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/data.rfa_test_ext', "test data\n");
    File::put($this->tmpDir.'/newfile.txt', "hello\n");

    $excludeFile = $this->tmpDir.'/.test_excludes';
    File::put($excludeFile, "*.rfa_test_ext\n");

    $entries = $this->service->getFileList($this->tmpDir, $excludeFile);
    $paths = collect($entries)->pluck('path')->all();

    expect($paths)->toContain('newfile.txt')
        ->and($paths)->not->toContain('data.rfa_test_ext');
});

test('getFileList ignores globalGitignorePath when file does not exist', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/tracked.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/data.rfa_test_ext', "test data\n");

    $entries = $this->service->getFileList($this->tmpDir, '/nonexistent/path');
    $paths = collect($entries)->pluck('path')->all();

    expect($paths)->toContain('data.rfa_test_ext');
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

test('getFileDiff respects contextLines parameter', function () {
    initRepo($this->tmpDir);
    // 20-line file, modify line 1 and line 20 to create 2 hunks with default context
    $lines = array_map(fn ($i) => "line{$i}", range(1, 20));
    File::put($this->tmpDir.'/many.txt', implode("\n", $lines)."\n");
    commitAll($this->tmpDir, 'initial');

    $lines[0] = 'changed1';
    $lines[19] = 'changed20';
    File::put($this->tmpDir.'/many.txt', implode("\n", $lines)."\n");

    $diff3 = $this->service->getFileDiff($this->tmpDir, 'many.txt');
    $diffFull = $this->service->getFileDiff($this->tmpDir, 'many.txt', contextLines: 99999);

    // Default (3 context lines) should produce 2 hunks
    expect(preg_match_all('/^@@ -/m', $diff3))->toBe(2);

    // Full context should produce 1 hunk starting at line 1
    expect(preg_match_all('/^@@ -/m', $diffFull))->toBe(1);
    expect($diffFull)->toContain('@@ -1,');
});

// -- GitCommandException tests --

test('getFileList throws GitCommandException for non-git directory', function () {
    // tmpDir is not a git repo (no initRepo call)
    $this->service->getFileList($this->tmpDir);
})->throws(GitCommandException::class);

// -- getFileContent tests --

test('getFileContent returns working directory content', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/data.txt', 'hello world');

    expect($this->service->getFileContent($this->tmpDir, 'data.txt'))->toBe('hello world');
});

test('getFileContent returns HEAD content via git show', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', 'original');
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/file.txt', 'modified');

    expect($this->service->getFileContent($this->tmpDir, 'file.txt', 'head'))->toBe('original');
});

test('getFileContent returns null for missing working file', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    expect($this->service->getFileContent($this->tmpDir, 'nonexistent.txt'))->toBeNull();
});

test('getFileContent returns null for file not in HEAD', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/untracked.txt', 'new');

    expect($this->service->getFileContent($this->tmpDir, 'untracked.txt', 'head'))->toBeNull();
});

// -- Unicode/emoji file path tests --

test('getFileList returns correct path for modified file with unicode name', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/⚡show.blade.php', "original\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/⚡show.blade.php', "changed\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->path)->toBe('⚡show.blade.php')
        ->and($entries[0]->status)->toBe('modified');
});

test('getFileList returns correct path for untracked file with emoji name', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/🚀launch.txt', "hello\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->path)->toBe('🚀launch.txt')
        ->and($entries[0]->isUntracked)->toBeTrue();
});

test('getFileDiff returns valid diff for unicode-named file', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/⚡show.blade.php', "line1\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/⚡show.blade.php', "line1\nline2\n");

    $diff = $this->service->getFileDiff($this->tmpDir, '⚡show.blade.php');

    expect($diff)->toStartWith('diff --git')
        ->and($diff)->toContain('⚡show.blade.php')
        ->and($diff)->toContain('+line2');
});

// -- GitCommandException tests --

test('GitCommandException carries stderr and exit code', function () {
    try {
        $this->service->getFileList($this->tmpDir);
    } catch (GitCommandException $e) {
        expect($e->exitCode)->toBeGreaterThan(0)
            ->and($e->stderr)->not->toBeEmpty()
            ->and($e->command)->toContain('diff');

        return;
    }

    test()->fail('Expected GitCommandException');
});

// -- getWorkingDirectoryFingerprint tests --

test('getWorkingDirectoryFingerprint changes when tracked file modified', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    commitAll($this->tmpDir, 'initial');

    $before = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    File::put($this->tmpDir.'/hello.txt', "changed\n");
    $after = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    expect($after)->not->toBe($before);
});

test('getWorkingDirectoryFingerprint changes when untracked file added', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    commitAll($this->tmpDir, 'initial');

    $before = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    File::put($this->tmpDir.'/newfile.txt', "hello\n");
    $after = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    expect($after)->not->toBe($before);
});

test('getWorkingDirectoryFingerprint is deterministic for same state', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    commitAll($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "changed\n");
    File::put($this->tmpDir.'/newfile.txt', "hello\n");

    $hash1 = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);
    $hash2 = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    expect($hash1)->toBe($hash2);
});

// -- getBranches tests --

test('getBranches returns current branch in local list', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    $result = $this->service->getBranches($this->tmpDir);

    expect($result['local'])->toHaveCount(1)
        ->and($result['local'][0]->name)->toBe('main')
        ->and($result['local'][0]->isCurrent)->toBeTrue()
        ->and($result['local'][0]->isRemote)->toBeFalse();
});

test('getBranches returns multiple local branches', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    exec('cd '.escapeshellarg($this->tmpDir).' && git branch feature-x');
    exec('cd '.escapeshellarg($this->tmpDir).' && git branch bugfix-y');

    $result = $this->service->getBranches($this->tmpDir);
    $names = array_map(fn ($b) => $b->name, $result['local']);

    expect($names)->toContain('main')
        ->and($names)->toContain('feature-x')
        ->and($names)->toContain('bugfix-y');

    $current = array_filter($result['local'], fn ($b) => $b->isCurrent);
    expect($current)->toHaveCount(1);
    expect(array_values($current)[0]->name)->toBe('main');
});

test('getBranches returns empty remote list when no remotes', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    commitAll($this->tmpDir, 'initial');

    $result = $this->service->getBranches($this->tmpDir);

    expect($result['remote'])->toBeEmpty();
});

// -- getCommitLog tests --

test('getCommitLog returns commits for current branch', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    commitAll($this->tmpDir, 'first commit');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    commitAll($this->tmpDir, 'second commit');

    $commits = $this->service->getCommitLog($this->tmpDir);

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->message)->toBe('second commit')
        ->and($commits[1]->message)->toBe('first commit');
});

test('getCommitLog respects limit parameter', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    commitAll($this->tmpDir, 'first');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    commitAll($this->tmpDir, 'second');

    File::put($this->tmpDir.'/file.txt', "v3\n");
    commitAll($this->tmpDir, 'third');

    $commits = $this->service->getCommitLog($this->tmpDir, limit: 2);

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->message)->toBe('third')
        ->and($commits[1]->message)->toBe('second');
});

test('getCommitLog respects offset parameter', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    commitAll($this->tmpDir, 'first');

    File::put($this->tmpDir.'/file.txt', "v2\n");
    commitAll($this->tmpDir, 'second');

    File::put($this->tmpDir.'/file.txt', "v3\n");
    commitAll($this->tmpDir, 'third');

    $commits = $this->service->getCommitLog($this->tmpDir, limit: 50, offset: 1);

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->message)->toBe('second')
        ->and($commits[1]->message)->toBe('first');
});

test('getCommitLog returns commits for specific branch', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    commitAll($this->tmpDir, 'main commit');

    exec('cd '.escapeshellarg($this->tmpDir).' && git checkout -b feature-branch');
    File::put($this->tmpDir.'/file.txt', "v2\n");
    commitAll($this->tmpDir, 'feature commit');

    exec('cd '.escapeshellarg($this->tmpDir).' && git checkout main');

    $commits = $this->service->getCommitLog($this->tmpDir, branch: 'feature-branch');

    expect($commits)->toHaveCount(2)
        ->and($commits[0]->message)->toBe('feature commit')
        ->and($commits[1]->message)->toBe('main commit');
});

test('getCommitLog returns entries with all fields populated', function () {
    initRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    commitAll($this->tmpDir, 'test commit');

    $commits = $this->service->getCommitLog($this->tmpDir);

    expect($commits)->toHaveCount(1);
    $commit = $commits[0];
    expect($commit->hash)->toHaveLength(40)
        ->and($commit->shortHash)->not->toBeEmpty()
        ->and($commit->message)->toBe('test commit')
        ->and($commit->author)->toBe('RFA Test')
        ->and($commit->relativeDate)->not->toBeEmpty()
        ->and($commit->date)->not->toBeEmpty();
});

test('getCommitLog returns empty array for empty repo', function () {
    initRepo($this->tmpDir);

    $commits = $this->service->getCommitLog($this->tmpDir);

    expect($commits)->toBeEmpty();
});

<?php

use App\DTOs\DiffTarget;
use App\Exceptions\GitCommandException;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->service = new GitDiffService(new GitProcessService, new IgnoreService);

    $ref = new ReflectionClass($this->service);

    $this->isBinary = $ref->getMethod('isBinary');
    $this->isBinary->setAccessible(true);

    $this->tmpDir = $this->createTempDirectory('rfa_git_test_');
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

// -- Repository helpers --

// -- getFileList tests --

test('getFileList returns modified file with correct status', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "line1\nchanged\nline3\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->path)->toBe('hello.txt');
    expect($entries[0]->status)->toBe('modified');
    expect($entries[0]->additions)->toBeGreaterThan(0);
    expect($entries[0]->isUntracked)->toBeFalse();
});

test('getFileList returns added file for untracked', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/tracked.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

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
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/doomed.txt', "bye\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::delete($this->tmpDir.'/doomed.txt');

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->path)->toBe('doomed.txt');
    expect($entries[0]->status)->toBe('deleted');
});

test('getFileList returns renamed file with oldPath', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/old_name.txt', "content\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $this->runTestRepoCommand($this->tmpDir, 'git mv old_name.txt new_name.txt');

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->status)->toBe('renamed');
    expect($entries[0]->path)->toBe('new_name.txt');
    expect($entries[0]->oldPath)->toBe('old_name.txt');
});

test('getFileList detects binary files', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/binary.bin', "hello\0world");

    $entries = $this->service->getFileList($this->tmpDir);

    $binary = collect($entries)->firstWhere('path', 'binary.bin');
    expect($binary)->not->toBeNull();
    expect($binary->isBinary)->toBeTrue();
});

test('getFileList excludes rfaignore patterns', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/keep.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/.rfaignore', "*.log\n");
    File::put($this->tmpDir.'/debug.log', "should not appear\n");
    File::put($this->tmpDir.'/visible.txt', "should appear\n");

    $entries = $this->service->getFileList($this->tmpDir);
    $paths = collect($entries)->pluck('path')->all();

    expect($paths)->toContain('visible.txt');
    expect($paths)->not->toContain('debug.log');
});

test('getFileList excludes untracked files matching globalGitignorePath', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/tracked.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

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
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/tracked.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/data.rfa_test_ext', "test data\n");

    $entries = $this->service->getFileList($this->tmpDir, '/nonexistent/path');
    $paths = collect($entries)->pluck('path')->all();

    expect($paths)->toContain('data.rfa_test_ext');
});

test('getFileList returns empty for clean repo', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toBeEmpty();
});

// -- mtime / byteSize fingerprint fields --

/**
 * Producer-side regression for the 1commit+WC / Since-base stale-diff
 * bug: the review page's softRefresh fingerprint depends on `mtime` and
 * `byteSize` being populated from the live filesystem in working-tree
 * mode. If `getFileList` ever stops setting them, the toast count
 * silently degrades to "always zero" and the bug is back.
 */
test('getFileList populates raw mtime and byteSize for working-tree entries', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');
    File::put($this->tmpDir.'/hello.txt', "v2 longer\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    $entry = $entries[0];
    expect($entry->mtime)->toBe(File::lastModified($this->tmpDir.'/hello.txt'));
    expect($entry->byteSize)->toBe(File::size($this->tmpDir.'/hello.txt'));
});

test('getFileList populates raw mtime and byteSize for untracked text entries', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/seed.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');
    File::put($this->tmpDir.'/new.txt', "fresh\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    $entry = $entries[0];
    expect($entry->isUntracked)->toBeTrue();
    expect($entry->mtime)->toBe(File::lastModified($this->tmpDir.'/new.txt'));
    expect($entry->byteSize)->toBe(File::size($this->tmpDir.'/new.txt'));
});

test('getFileList mtime advances after rewriting a working-tree file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "before\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "edit-1\n");
    clearstatcache(true, $this->tmpDir.'/hello.txt');
    $first = $this->service->getFileList($this->tmpDir)[0];

    // Write, then pin mtime so the assertion isn't a same-second flake.
    File::put($this->tmpDir.'/hello.txt', "edit-2\n");
    touch($this->tmpDir.'/hello.txt', $first->mtime + 5);
    clearstatcache(true, $this->tmpDir.'/hello.txt');
    $second = $this->service->getFileList($this->tmpDir)[0];

    expect($second->mtime)->toBeGreaterThan($first->mtime);
});

/**
 * 1commit+WC and Since-base are both `rangeToWorking` targets. Same
 * branch as plain WT for `isWorkingDirectory()`, so mtime/byteSize must
 * still be populated — the fix relies on this.
 */
test('getFileList populates mtime/byteSize in rangeToWorking (1commit+WC / Since-base) mode', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'c1');
    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'c2');
    File::put($this->tmpDir.'/file.txt', "v3 wc\n");

    $entries = $this->service->getFileList(
        $this->tmpDir,
        target: DiffTarget::rangeToWorking('HEAD~1'),
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0]->mtime)->toBe(File::lastModified($this->tmpDir.'/file.txt'));
    expect($entries[0]->byteSize)->toBe(File::size($this->tmpDir.'/file.txt'));
});

test('getFileList leaves mtime/byteSize null for immutable (commit-to-commit) targets', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "v1\n");
    $this->commitTestRepo($this->tmpDir, 'c1');
    File::put($this->tmpDir.'/file.txt', "v2\n");
    $this->commitTestRepo($this->tmpDir, 'c2');

    $entries = $this->service->getFileList(
        $this->tmpDir,
        target: DiffTarget::range('HEAD~1', 'HEAD'),
    );

    expect($entries)->toHaveCount(1);
    expect($entries[0]->mtime)->toBeNull();
    expect($entries[0]->byteSize)->toBeNull();
});

// -- getFileDiff tests --

test('getFileDiff returns raw diff for tracked file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'hello.txt');

    expect($diff)->toStartWith('diff --git');
    expect($diff)->toContain('+line2');
});

test('getFileDiff returns synthetic diff for untracked file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/newfile.txt', "hello\nworld\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'newfile.txt', isUntracked: true);

    expect($diff)->toContain('new file mode');
    expect($diff)->toContain('+hello');
});

test('getFileDiff returns null when diff exceeds max bytes', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/big.txt', "small\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/big.txt', str_repeat("long line of content\n", 500));

    $diff = $this->service->getFileDiff($this->tmpDir, 'big.txt', maxBytes: 100);

    expect($diff)->toBeNull();
});

test('getFileDiff handles binary untracked file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/image.bin', "png\0data");

    $diff = $this->service->getFileDiff($this->tmpDir, 'image.bin', isUntracked: true);

    expect($diff)->toContain('Binary files');
});

test('getFileDiff returns empty string for missing untracked file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $diff = $this->service->getFileDiff($this->tmpDir, 'gone.txt', isUntracked: true);

    expect($diff)->toBe('');
});

test('getFileDiff untracked file with trailing newline has correct line count', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/newfile.txt', "line1\nline2\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'newfile.txt', isUntracked: true);

    // Should be +1,2 (2 real lines), not +1,3
    expect($diff)->toContain('@@ -0,0 +1,2 @@');
    expect($diff)->toContain('+line1');
    expect($diff)->toContain('+line2');
});

test('getFileDiff handles empty untracked file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/empty.txt', '');

    $diff = $this->service->getFileDiff($this->tmpDir, 'empty.txt', isUntracked: true);

    expect($diff)->toContain('new file mode');
    expect($diff)->not->toContain('@@ ');
});

test('getFileDiff respects contextLines parameter', function () {
    $this->initTestRepo($this->tmpDir);
    // 20-line file, modify line 1 and line 20 to create 2 hunks with default context
    $lines = array_map(fn ($i) => "line{$i}", range(1, 20));
    File::put($this->tmpDir.'/many.txt', implode("\n", $lines)."\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

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

// -- Symlink tests --

test('getFileList detects untracked symlink', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/target.md', "content\n");
    symlink('target.md', $this->tmpDir.'/link.md');

    $entries = $this->service->getFileList($this->tmpDir);

    $link = collect($entries)->firstWhere('path', 'link.md');
    expect($link)->not->toBeNull();
    expect($link->isSymlink)->toBeTrue();
    expect($link->symlinkTarget)->toBe('target.md');
    expect($link->additions)->toBe(1);
    expect($link->isUntracked)->toBeTrue();
});

test('getFileList detects broken symlink', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    symlink('nonexistent.md', $this->tmpDir.'/broken.md');

    $entries = $this->service->getFileList($this->tmpDir);

    $link = collect($entries)->firstWhere('path', 'broken.md');
    expect($link)->not->toBeNull();
    expect($link->isSymlink)->toBeTrue();
    expect($link->symlinkTarget)->toBe('nonexistent.md');
});

test('buildUntrackedDiff generates mode 120000 for symlinks', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/target.md', "content\n");
    symlink('target.md', $this->tmpDir.'/link.md');

    $diff = $this->service->getFileDiff($this->tmpDir, 'link.md', isUntracked: true);

    expect($diff)->toContain('new file mode 120000');
    expect($diff)->toContain('+target.md');
});

// -- Unicode/emoji file path tests --

test('getFileList returns correct path for modified file with unicode name', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/⚡show.blade.php', "original\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/⚡show.blade.php', "changed\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->path)->toBe('⚡show.blade.php')
        ->and($entries[0]->status)->toBe('modified');
});

test('getFileList returns correct path for untracked file with emoji name', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/🚀launch.txt', "hello\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->path)->toBe('🚀launch.txt')
        ->and($entries[0]->isUntracked)->toBeTrue();
});

test('getFileDiff returns valid diff for unicode-named file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/⚡show.blade.php', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/⚡show.blade.php', "line1\nline2\n");

    $diff = $this->service->getFileDiff($this->tmpDir, '⚡show.blade.php');

    expect($diff)->toStartWith('diff --git')
        ->and($diff)->toContain('⚡show.blade.php')
        ->and($diff)->toContain('+line2');
});

// -- getNewFileLineCount tests --

test('getNewFileLineCount returns line count for working directory file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\nline3\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "line1\nline2\nline3\nline4\n");

    $count = $this->service->getNewFileLineCount($this->tmpDir, 'hello.txt');

    expect($count)->toBe(4);
});

test('getNewFileLineCount handles file without trailing newline', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "line1\nline2");

    $count = $this->service->getNewFileLineCount($this->tmpDir, 'hello.txt');

    expect($count)->toBe(2);
});

test('getNewFileLineCount returns null for missing file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $count = $this->service->getNewFileLineCount($this->tmpDir, 'nonexistent.txt');

    expect($count)->toBeNull();
});

test('getNewFileLineCount returns 0 for empty file', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/empty.txt', '');

    $count = $this->service->getNewFileLineCount($this->tmpDir, 'empty.txt');

    expect($count)->toBe(0);
});

test('getNewFileLineCount returns line count for commit target', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\nline3\n");
    $this->commitTestRepo($this->tmpDir, 'three lines');

    $commitHash = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));
    $target = DiffTarget::commit($commitHash);

    $count = $this->service->getNewFileLineCount($this->tmpDir, 'hello.txt', $target);

    expect($count)->toBe(3);
});

test('getNewFileLineCount returns null for deleted file in commit', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $target = DiffTarget::commit(trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD')));

    $count = $this->service->getNewFileLineCount($this->tmpDir, 'nonexistent.txt', $target);

    expect($count)->toBeNull();
});

// -- GitCommandException tests --

test('getFileList throws GitCommandException for non-git directory', function () {
    // tmpDir is not a git repo (no initRepo call)
    $this->service->getFileList($this->tmpDir);
})->throws(GitCommandException::class);

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
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $before = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    File::put($this->tmpDir.'/hello.txt', "changed\n");
    $after = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    expect($after)->not->toBe($before);
});

test('getWorkingDirectoryFingerprint changes when untracked file added', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $before = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    File::put($this->tmpDir.'/newfile.txt', "hello\n");
    $after = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    expect($after)->not->toBe($before);
});

test('getWorkingDirectoryFingerprint is deterministic for same state', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/hello.txt', "changed\n");
    File::put($this->tmpDir.'/newfile.txt', "hello\n");

    $hash1 = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);
    $hash2 = $this->service->getWorkingDirectoryFingerprint($this->tmpDir);

    expect($hash1)->toBe($hash2);
});

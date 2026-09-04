<?php

use App\DTOs\DiffTarget;
use App\Exceptions\GitCommandException;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use App\Support\AnsiText;
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

test('getFileList limits tracked and untracked discovery to one path', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/first.txt', "one\n");
    File::put($this->tmpDir.'/second.txt', "two\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/first.txt', "changed\n");
    File::put($this->tmpDir.'/second.txt', "changed\n");
    File::put($this->tmpDir.'/third.txt', "new\n");

    $modified = $this->service->getFileList($this->tmpDir, onlyPath: 'second.txt');
    $untracked = $this->service->getFileList($this->tmpDir, onlyPath: 'third.txt');

    expect($modified)->toHaveCount(1)
        ->and($modified[0]->path)->toBe('second.txt')
        ->and($untracked)->toHaveCount(1)
        ->and($untracked[0]->path)->toBe('third.txt');
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

test('getFileList reports additions for a renamed-and-modified repo-root file', function () {
    // numstat renders a root rename as a brace-less `old => new`; the parser must
    // resolve that to the new path so the stats land on the entry instead of
    // defaulting to +0/-0. A nested rename uses the brace form and is covered by
    // git's compact notation path.
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/root.txt', "line1\nline2\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $this->runTestRepoCommand($this->tmpDir, 'git mv root.txt renamed.txt');
    File::put($this->tmpDir.'/renamed.txt', "line1\nline2\nline3\nline4\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->status)->toBe('renamed');
    expect($entries[0]->path)->toBe('renamed.txt');
    expect($entries[0]->oldPath)->toBe('root.txt');
    expect($entries[0]->additions)->toBeGreaterThan(0);
});

test('getFileList reports additions for a nested renamed-and-modified file', function () {
    // The brace form `dir/{old => new}` path: confirm stats still attach after
    // the rewrite to the new path.
    $this->initTestRepo($this->tmpDir);
    File::makeDirectory($this->tmpDir.'/src', 0755, true);
    File::put($this->tmpDir.'/src/old.txt', "a\nb\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $this->runTestRepoCommand($this->tmpDir, 'git mv src/old.txt src/new.txt');
    File::put($this->tmpDir.'/src/new.txt', "a\nb\nc\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->status)->toBe('renamed');
    expect($entries[0]->path)->toBe('src/new.txt');
    expect($entries[0]->additions)->toBeGreaterThan(0);
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

test('a negation re-includes a tracked file the same way it does an untracked one', function () {
    $this->initTestRepo($this->tmpDir);
    File::makeDirectory($this->tmpDir.'/logs');
    File::put($this->tmpDir.'/logs/tracked.log', "before\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/.rfaignore', "*.log\n!logs/tracked.log\n!logs/untracked.log\n");
    File::put($this->tmpDir.'/logs/tracked.log', "after\n");
    File::put($this->tmpDir.'/logs/untracked.log', "new\n");
    File::put($this->tmpDir.'/logs/hidden.log', "hidden\n");

    $paths = collect($this->service->getFileList($this->tmpDir))->pluck('path')->all();

    expect($paths)->toContain('logs/tracked.log')
        ->and($paths)->toContain('logs/untracked.log')
        ->and($paths)->not->toContain('logs/hidden.log');
});

test('a tracked lock file stays excluded even when rfaignore re-includes it', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/composer.lock', "{}\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/.rfaignore', "!composer.lock\n");
    File::put($this->tmpDir.'/composer.lock', "{\"changed\": true}\n");

    $paths = collect($this->service->getFileList($this->tmpDir))->pluck('path')->all();

    expect($paths)->not->toContain('composer.lock');
});

test('a rename out of an ignored directory stays visible as a rename', function () {
    $this->initTestRepo($this->tmpDir);
    File::makeDirectory($this->tmpDir.'/vendored');
    File::put($this->tmpDir.'/vendored/lib.js', str_repeat("const answer = 42;\n", 20));
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/.rfaignore', "vendored/\n");
    $this->runTestRepoCommand($this->tmpDir, 'git mv vendored/lib.js lib.js');

    $entry = collect($this->service->getFileList($this->tmpDir))->firstWhere('path', 'lib.js');

    expect($entry)->not->toBeNull()
        ->and($entry->status)->toBe('renamed')
        ->and($entry->oldPath)->toBe('vendored/lib.js');
});

test('the working-directory fingerprint covers exactly the files getFileList shows', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/keep.txt', "before\n");
    File::put($this->tmpDir.'/build.log', "before\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/.rfaignore', "*.log\n");
    File::put($this->tmpDir.'/keep.txt', "after\n");
    File::put($this->tmpDir.'/build.log', "after\n");

    $status = $this->service->getWorkingDirectoryStatus($this->tmpDir);
    $listed = $this->service->getFileList($this->tmpDir);

    expect($status['count'])->toBe(count($listed));
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
 * still be populated. The fix relies on this.
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

test('getFileList returns the whole repo as added files from the empty tree', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/a.txt', "a\n");
    File::put($this->tmpDir.'/b.txt', "b\n");
    $this->commitTestRepo($this->tmpDir, 'c1');

    $entries = $this->service->getFileList(
        $this->tmpDir,
        target: DiffTarget::rangeToWorking(DiffTarget::EMPTY_TREE_HASH),
    );

    expect($entries)->toHaveCount(2)
        ->and(collect($entries)->pluck('status')->unique()->all())->toBe(['added'])
        ->and(collect($entries)->pluck('path')->sort()->values()->all())->toBe(['a.txt', 'b.txt']);
});

test('getFileList returns working files from the empty tree on an unborn repo', function () {
    // No commit: HEAD is unborn, so `git diff HEAD` would fail. Diffing from the
    // empty tree still surfaces the working files for the entire-repo view.
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/wip.txt', "wip\n");

    $entries = $this->service->getFileList(
        $this->tmpDir,
        target: DiffTarget::rangeToWorking(DiffTarget::EMPTY_TREE_HASH),
    );

    expect(collect($entries)->pluck('path')->all())->toContain('wip.txt');
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

test('getFileDiff keeps git prefixes parseable despite repo diff config', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');
    $this->runTestRepoCommand($this->tmpDir, [
        'git config diff.noprefix true',
        'git config diff.mnemonicPrefix true',
        'git config diff.srcPrefix custom-old/',
        'git config diff.dstPrefix custom-new/',
    ]);

    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'hello.txt');

    expect($diff)->toStartWith('diff --git a/hello.txt b/hello.txt')
        ->and($diff)->toContain('--- a/hello.txt')
        ->and($diff)->toContain('+++ b/hello.txt')
        ->and($diff)->not->toContain('custom-old/')
        ->and($diff)->not->toContain('custom-new/');
});

test('getFileDiff keeps color disabled by default even when git color is forced', function () {
    config([
        'rfa.moved_lines.enabled' => false,
    ]);

    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "alpha\none\nbody-a\nbody-b\nfooter\nomega\n");
    $this->commitTestRepo($this->tmpDir, 'initial');
    $this->runTestRepoCommand($this->tmpDir, 'git config color.ui always');

    File::put($this->tmpDir.'/file.txt', "alpha\nbody-a\nbody-b\nfooter\none\nomega\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'file.txt');

    expect($diff)->not->toContain("\e[")
        ->and($diff)->toContain('-one')
        ->and($diff)->toContain('+one');
});

test('getFileDiff can emit moved line colors when enabled', function () {
    config([
        'rfa.moved_lines.enabled' => true,
        'rfa.moved_lines.mode' => 'plain',
    ]);

    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "alpha\none\nbody-a\nbody-b\nfooter\nomega\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/file.txt', "alpha\nbody-a\nbody-b\nfooter\none\nomega\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'file.txt');

    expect($diff)->toContain("\e[1;35m-one")
        ->and($diff)->toContain("\e[1;36m+\e[m\e[1;36mone");
});

test('getFileDiff ignores configured external diff commands', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $script = $this->tmpDir.'/external-diff.sh';
    File::put($script, "#!/bin/sh\necho external-diff-ran\nexit 0\n");
    chmod($script, 0755);
    $this->runTestRepoCommand($this->tmpDir, 'git config diff.external '.escapeshellarg($script));

    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'hello.txt');

    expect($diff)->toContain('+line2')
        ->and($diff)->not->toContain('external-diff-ran');
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

test('getFileDiff returns empty string for path traversal', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $diff = $this->service->getFileDiff($this->tmpDir, '../outside.txt', isUntracked: true);

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

test('buildUntrackedDiff represents escaping symlinks without following them', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/readme.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $outside = $this->createTempDirectory('rfa_gitdiff_outside_');
    File::put($outside.'/secret.md', "outside\n");
    symlink($outside.'/secret.md', $this->tmpDir.'/link.md');

    $diff = $this->service->getFileDiff($this->tmpDir, 'link.md', isUntracked: true);

    expect($diff)->toContain('new file mode 120000');
    expect($diff)->toContain('+'.$outside.'/secret.md');
    expect($diff)->not->toContain('+outside');
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

test('getFileList keeps status paths plain when git color is forced', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');
    $this->runTestRepoCommand($this->tmpDir, 'git config color.ui always');

    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $entries = $this->service->getFileList($this->tmpDir);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->path)->toBe('hello.txt')
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

test('getNewFileLineCount returns null for working tree symlink escape', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    $outside = $this->createTempDirectory('rfa_gitdiff_linecount_outside_');
    File::put($outside.'/secret.txt', "outside\n");
    symlink($outside.'/secret.txt', $this->tmpDir.'/escape.txt');

    $count = $this->service->getNewFileLineCount($this->tmpDir, 'escape.txt');

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

test('getFileDiff returns a plain diff when moved line colors are not requested', function () {
    config([
        'rfa.moved_lines.enabled' => true,
        'rfa.moved_lines.mode' => 'plain',
    ]);

    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "alpha\none\nbody-a\nbody-b\nfooter\nomega\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/file.txt', "alpha\nbody-a\nbody-b\nfooter\none\nomega\n");

    $diff = $this->service->getFileDiff($this->tmpDir, 'file.txt', detectMovedLines: false);

    expect($diff)->not->toContain("\e[")
        ->and($diff)->toContain('-one')
        ->and($diff)->toContain('+one');
});

test('getFileDiff size cap ignores ANSI color overhead from moved line detection', function () {
    config([
        'rfa.moved_lines.enabled' => true,
        'rfa.moved_lines.mode' => 'plain',
    ]);

    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/file.txt', "alpha\none\nbody-a\nbody-b\nfooter\nomega\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/file.txt', "alpha\nbody-a\nbody-b\nfooter\none\nomega\n");

    $colorized = $this->service->getFileDiff($this->tmpDir, 'file.txt');
    $plainLength = strlen(AnsiText::strip($colorized));

    expect(strlen($colorized))->toBeGreaterThan($plainLength)
        ->and($this->service->getFileDiff($this->tmpDir, 'file.txt', maxBytes: $plainLength))->not->toBeNull()
        ->and($this->service->getFileDiff($this->tmpDir, 'file.txt', maxBytes: $plainLength - 1))->toBeNull();
});

test('getFileDiff serves commit-range diffs for paths under a directory symlinked outside the repo', function () {
    $this->initTestRepo($this->tmpDir);
    File::makeDirectory($this->tmpDir.'/lib');
    File::put($this->tmpDir.'/lib/file.txt', "one\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    File::put($this->tmpDir.'/lib/file.txt', "one\ntwo\n");
    $this->commitTestRepo($this->tmpDir, 'second');

    $from = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD~1'));
    $to = trim($this->runTestRepoCommand($this->tmpDir, 'git rev-parse HEAD'));

    // Replace the committed directory with a symlink resolving outside the
    // repo; the historical diff must not depend on worktree resolution.
    $outside = $this->createTempDirectory('rfa_outside_');
    File::deleteDirectory($this->tmpDir.'/lib');
    symlink($outside, $this->tmpDir.'/lib');

    $diff = $this->service->getFileDiff($this->tmpDir, 'lib/file.txt', target: DiffTarget::range($from, $to));

    expect($diff)->toContain('diff --git a/lib/file.txt b/lib/file.txt')
        ->and($diff)->toContain('+two');
});

test('getFileDiff still rejects traversal paths', function () {
    $this->initTestRepo($this->tmpDir);
    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'initial');

    expect($this->service->getFileDiff($this->tmpDir, '../outside.txt'))->toBe('')
        ->and($this->service->getFileDiff($this->tmpDir, 'hello.txt', oldPath: '/etc/passwd'))->toBe('');
});

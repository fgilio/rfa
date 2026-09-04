<?php

use App\Services\ExternalFilesService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = app(ExternalFilesService::class);

    $this->extDir = $this->createTempDirectory('rfa_ext_dir_');
    File::put($this->extDir.'/note.md', "# Title\n\nbody\n");
    File::ensureDirectoryExists($this->extDir.'/sub');
    File::put($this->extDir.'/sub/inner.md', "inner\n");

    $this->configs = [['label' => 'notes', 'path' => $this->extDir]];
});

test('returns empty list when no configs are supplied', function () {
    expect($this->service->getEntries([]))->toBe([]);
});

test('does not follow symlinks out of the configured root', function () {
    $secret = $this->createTempDirectory('rfa_ext_secret_');
    File::put($secret.'/secret.md', "secret\n");

    symlink($secret, $this->extDir.'/escape');

    $paths = collect($this->service->getEntries($this->configs))->pluck('path')->all();

    // The legitimate file is still listed.
    expect($paths)->toContain('external/notes/note.md');
    // But the symlink target's contents are NOT walked.
    foreach ($paths as $p) {
        expect($p)->not->toContain('secret.md');
    }
});

test('caps recursion at MAX_DEPTH so a misconfigured root cannot pull in a huge subtree', function () {
    $deep = $this->createTempDirectory('rfa_ext_deep_');
    $segments = array_fill(0, ExternalFilesService::MAX_DEPTH + 3, 'd');
    File::ensureDirectoryExists($deep.'/'.implode('/', $segments));
    File::put($deep.'/top.md', "shallow\n");
    File::put($deep.'/'.implode('/', $segments).'/buried.md', "too deep\n");

    $paths = collect($this->service->getEntries([['label' => 'deep', 'path' => $deep]]))
        ->pluck('path')
        ->all();

    expect($paths)->toContain('external/deep/top.md');
    foreach ($paths as $p) {
        expect($p)->not->toContain('buried.md');
    }
});

test('walks the configured directory recursively and mounts files under external/<label>', function () {
    $entries = $this->service->getEntries($this->configs);

    expect($entries)->toHaveCount(2);

    $paths = collect($entries)->pluck('path')->all();
    expect($paths)->toContain('external/notes/note.md');
    expect($paths)->toContain('external/notes/sub/inner.md');
});

test('marks every entry as external/added/non-binary with an absolute path back to disk', function () {
    [$entry] = $this->service->getEntries($this->configs);

    expect($entry->isExternal)->toBeTrue();
    expect($entry->isUntracked)->toBeFalse();
    expect($entry->isBinary)->toBeFalse();
    expect($entry->status)->toBe('added');
    expect($entry->externalAbsolutePath)->not->toBeNull();
    expect(file_exists($entry->externalAbsolutePath))->toBeTrue();
});

test('skips binary files', function () {
    File::put($this->extDir.'/binary.bin', "ok\0bytes\0here\n");

    $paths = collect($this->service->getEntries($this->configs))->pluck('path')->all();

    expect($paths)->not->toContain('external/notes/binary.bin');
});

test('defaults the label to the directory basename when none is supplied', function () {
    $entries = $this->service->getEntries([['path' => $this->extDir]]);

    expect($entries[0]->path)->toStartWith('external/'.basename($this->extDir).'/');
});

test('uniqueLabelFor picks a fresh suffix when the candidate clashes with existing labels', function () {
    $existing = [
        ['label' => 'notes', 'path' => '/tmp/a'],
        ['label' => 'notes-2', 'path' => '/tmp/b'],
    ];

    expect($this->service->uniqueLabelFor($existing, 'notes'))->toBe('notes-3');
    expect($this->service->uniqueLabelFor($existing, 'fresh'))->toBe('fresh');
});

test('uses stored labels literally at read time so unlinking a sibling cannot rename survivors', function () {
    $other = $this->createTempDirectory('rfa_ext_dir_b_');
    File::put($other.'/x.md', "x\n");

    // Stored shape after Link assigned the disambiguated label at write time.
    $configs = [
        ['label' => 'notes', 'path' => $this->extDir],
        ['label' => 'notes-2', 'path' => $other],
    ];

    // Unlinking the first row leaves 'notes-2' alone; it must not collapse back to 'notes'.
    $survivorOnly = [['label' => 'notes-2', 'path' => $other]];

    expect(collect($this->service->getEntries($survivorOnly))->pluck('path')->all())
        ->toContain('external/notes-2/x.md');
});

test('drops configs with non-existent paths', function () {
    $paths = collect($this->service->getEntries([
        ['label' => 'good', 'path' => $this->extDir],
        ['label' => 'gone', 'path' => '/this/path/does/not/exist'],
    ]))->pluck('path')->all();

    expect($paths)->toContain('external/good/note.md');
    foreach ($paths as $p) {
        expect($p)->not->toStartWith('external/gone');
    }
});

test('rejects named pipes without opening them', function () {
    if (! function_exists('posix_mkfifo')) {
        $this->markTestSkipped('POSIX FIFO support is unavailable.');
    }

    $pipe = $this->extDir.'/events.pipe';

    expect(posix_mkfifo($pipe, 0600))->toBeTrue();

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('fileExists')->once()->with('events.pipe')->andReturn(false);
    $disk->shouldNotReceive('readStream');
    Storage::shouldReceive('build')->once()->andReturn($disk);

    expect($this->service->canonicalFilePath($pipe))->toBeNull();
});

test('resolveAbsolutePath round-trips a mount path back to its on-disk file', function () {
    $absolute = $this->service->resolveAbsolutePath($this->configs, 'external/notes/note.md');

    expect($absolute)->toBe(realpath($this->extDir.'/note.md'));
});

test('loads one configured mount without returning its siblings', function () {
    $entry = $this->service->getEntry($this->configs, 'external/notes/sub/inner.md');

    expect($entry)->not->toBeNull()
        ->and($entry->path)->toBe('external/notes/sub/inner.md')
        ->and($entry->externalAbsolutePath)->toBe(realpath($this->extDir.'/sub/inner.md'));
});

test('resolveAbsolutePath returns null for unknown mounts', function () {
    expect($this->service->resolveAbsolutePath($this->configs, 'external/notes/missing.md'))->toBeNull();
    expect($this->service->resolveAbsolutePath($this->configs, 'src/anything.php'))->toBeNull();
});

test('resolveAbsolutePath rejects path traversal escapes', function () {
    $escape = 'external/notes/../../../etc/passwd';

    expect($this->service->resolveAbsolutePath($this->configs, $escape))->toBeNull();
});

test('buildDiff emits a synthetic /dev/null whole-file diff', function () {
    $absolute = $this->extDir.'/note.md';
    $diff = $this->service->buildDiff($absolute, 'external/notes/note.md');

    expect($diff)->toContain('diff --git a/external/notes/note.md b/external/notes/note.md');
    expect($diff)->toContain('--- /dev/null');
    expect($diff)->toContain('+++ b/external/notes/note.md');
    expect($diff)->toContain('+# Title');
    expect($diff)->toContain('+body');
});

test('buildDiff returns null for files larger than the configured cap', function () {
    $absolute = $this->extDir.'/note.md';

    expect($this->service->buildDiff($absolute, 'external/notes/note.md', maxBytes: 1))->toBeNull();
});

test('buildDiff returns empty string when the file no longer exists on disk', function () {
    expect($this->service->buildDiff($this->extDir.'/missing.md', 'external/notes/missing.md'))->toBe('');
});

// -- single-file configs --

test('mounts a single-file config as one entry at external/<label> with no subpath', function () {
    $file = $this->extDir.'/plan.md';
    File::put($file, "# plan\n");

    $entries = $this->service->getEntries([['label' => 'plan.md', 'path' => $file]]);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->path)->toBe('external/plan.md');
    expect($entries[0]->isExternal)->toBeTrue();
    expect($entries[0]->externalAbsolutePath)->toBe(realpath($file));
});

test('resolveAbsolutePath round-trips a single-file mount back to its on-disk file', function () {
    $file = $this->extDir.'/plan.md';
    File::put($file, "# plan\n");
    $configs = [['label' => 'plan.md', 'path' => $file]];

    expect($this->service->resolveAbsolutePath($configs, 'external/plan.md'))->toBe(realpath($file));
});

test('resolveAbsolutePath returns null when a single-file mount is queried with a child path', function () {
    $file = $this->extDir.'/plan.md';
    File::put($file, "# plan\n");
    $configs = [['label' => 'plan.md', 'path' => $file]];

    expect($this->service->resolveAbsolutePath($configs, 'external/plan.md/anything'))->toBeNull();
});

test('surfaces a single-file binary as a binary entry rather than dropping it', function () {
    $bin = $this->extDir.'/blob.bin';
    file_put_contents($bin, "ok\0bytes\0here\n");

    $entries = $this->service->getEntries([['label' => 'blob.bin', 'path' => $bin]]);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->isBinary)->toBeTrue();
    expect($entries[0]->additions)->toBe(0);
});

test('directory and single-file configs coexist on the same project', function () {
    $file = $this->createTempDirectory('rfa_ext_file_').'/plan.md';
    File::put($file, "# plan\n");

    $entries = $this->service->getEntries([
        ['label' => 'notes', 'path' => $this->extDir],
        ['label' => 'plan.md', 'path' => $file],
    ]);

    $paths = collect($entries)->pluck('path')->all();
    expect($paths)->toContain('external/notes/note.md');
    expect($paths)->toContain('external/plan.md');
});

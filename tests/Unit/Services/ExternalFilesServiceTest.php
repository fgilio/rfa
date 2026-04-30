<?php

use App\Services\ExternalFilesService;
use Illuminate\Support\Facades\File;
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

test('disambiguates duplicate labels with a numeric suffix', function () {
    $other = $this->createTempDirectory('rfa_ext_dir_b_');
    File::put($other.'/x.md', "x\n");

    $paths = collect($this->service->getEntries([
        ['label' => 'notes', 'path' => $this->extDir],
        ['label' => 'notes', 'path' => $other],
    ]))->pluck('path')->all();

    expect($paths)->toContain('external/notes/note.md');
    expect($paths)->toContain('external/notes-2/x.md');
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

test('resolveAbsolutePath round-trips a mount path back to its on-disk file', function () {
    $absolute = $this->service->resolveAbsolutePath($this->configs, 'external/notes/note.md');

    expect($absolute)->toBe(realpath($this->extDir.'/note.md'));
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

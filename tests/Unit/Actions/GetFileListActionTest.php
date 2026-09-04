<?php

use App\Actions\GetFileListAction;
use App\DTOs\DiffTarget;
use App\Models\Project;
use App\Services\ExternalFilesService;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use App\Services\ReviewConfigService;
use App\Support\DiffCacheKey;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_filelist_test_');

    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "ok\n");
    $this->commitTestRepo($this->tmpDir, 'init');
});

test('returns files as arrays with id field', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $files = $action->handle($this->tmpDir);

    expect($files)->toHaveCount(1);
    expect($files[0])->toHaveKeys(['id', 'path', 'status', 'additions', 'deletions', 'isBinary', 'isUntracked']);
    expect($files[0]['id'])->toStartWith('file-');
    expect($files[0]['path'])->toBe('file.txt');
});

test('scopes Git discovery to one requested file', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");
    File::put($this->tmpDir.'/other.txt', "other\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $files = $action->handle($this->tmpDir, onlyPath: 'file.txt');

    expect($files)->toHaveCount(1)
        ->and($files[0]['path'])->toBe('file.txt')
        ->and($files[0]['isExternal'])->toBeFalse();
});

test('surfaces an unchanged requested file as a whole-file review', function () {
    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $files = $action->handle($this->tmpDir, onlyPath: 'file.txt');

    expect($files)->toHaveCount(1)
        ->and($files[0]['path'])->toBe('file.txt')
        ->and($files[0]['status'])->toBe('added')
        ->and($files[0]['isExternal'])->toBeTrue()
        ->and($files[0]['externalAbsolutePath'])->toBe(realpath($this->tmpDir.'/file.txt'));
});

test('loads only the requested configured external file', function () {
    $externalDirectory = $this->createTempDirectory('rfa_focused_external_');
    File::put($externalDirectory.'/first.md', "first\n");
    File::put($externalDirectory.'/second.md', "second\n");

    $project = Project::factory()->create([
        'path' => $this->tmpDir,
        'git_common_dir' => $this->tmpDir.'/.git',
        'external_paths' => [['label' => 'notes', 'path' => $externalDirectory]],
    ]);

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $files = $action->handle(
        $this->tmpDir,
        projectId: $project->id,
        onlyPath: 'external/notes/second.md',
    );

    expect($files)->toHaveCount(1)
        ->and($files[0]['path'])->toBe('external/notes/second.md')
        ->and($files[0]['externalAbsolutePath'])->toBe(realpath($externalDirectory.'/second.md'));
});

test('rejects an unsafe requested path without broadening to the repository', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));

    expect($action->handle($this->tmpDir, onlyPath: '../file.txt'))->toBe([]);
});

test('loads typed changeset using existing file entries', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $changeset = $action->changeset($this->tmpDir);

    expect($changeset->repoPath)->toBe($this->tmpDir)
        ->and($changeset->sourceLabel)->toBe('HEAD..working')
        ->and($changeset->target->contextKey())->toBe('HEAD..working')
        ->and($changeset->files)->toHaveCount(1)
        ->and($changeset->files[0]->path)->toBe('file.txt')
        ->and($changeset->filesToArray()[0])->toHaveKeys(['id', 'path', 'status', 'additions', 'deletions', 'isBinary', 'isUntracked']);
});

test('returns the changeset files folders-first', function () {
    // `z/` sorts after the root file `a.txt` in git's flat path order, so a
    // folders-first result (directory contents first, nested folders before
    // loose files) proves changeset() applies FilePathSorter, not git's order.
    File::makeDirectory($this->tmpDir.'/z/c', recursive: true);
    File::put($this->tmpDir.'/a.txt', "a\n");
    File::put($this->tmpDir.'/z/b.txt', "b\n");
    File::put($this->tmpDir.'/z/c/d.txt', "d\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $paths = collect($action->changeset($this->tmpDir)->files)->map(fn ($file) => $file->path)->all();

    expect($paths)->toBe([
        'z/c/d.txt',
        'z/b.txt',
        'a.txt',
    ]);
});

test('clears cache by default', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $files = $action->handle($this->tmpDir);

    $cacheKey = DiffCacheKey::for($this->tmpDir, $files[0]['id'], reviewFingerprint());
    Cache::put($cacheKey, 'stale', 60);

    $action->handle($this->tmpDir);

    expect(Cache::has($cacheKey))->toBeFalse();
});

test('clears the full-context cache variant too', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $files = $action->handle($this->tmpDir);

    $baseKey = DiffCacheKey::for($this->tmpDir, $files[0]['id'], reviewFingerprint());
    $fullContextKey = DiffCacheKey::for($this->tmpDir, $files[0]['id'], reviewFingerprint(), DiffTarget::workingDirectory()->contextKey().':full-context');
    Cache::put($baseKey, 'stale-base', 60);
    Cache::put($fullContextKey, 'stale-full', 60);

    $action->handle($this->tmpDir);

    expect(Cache::has($baseKey))->toBeFalse();
    expect(Cache::has($fullContextKey))->toBeFalse();
});

test('preserves cache when clearCache is false', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $files = $action->handle($this->tmpDir, clearCache: false);

    $cacheKey = DiffCacheKey::for($this->tmpDir, $files[0]['id'], reviewFingerprint());
    Cache::put($cacheKey, 'kept', 60);

    $action->handle($this->tmpDir, clearCache: false);

    expect(Cache::get($cacheKey))->toBe('kept');
});

test('appends entries from configured external paths in working-tree mode', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $extDir = $this->createTempDirectory('rfa_filelist_ext_');
    File::put($extDir.'/note.md', "external content\n");

    $project = Project::factory()->create([
        'path' => $this->tmpDir,
        'git_common_dir' => $this->tmpDir.'/.git',
        'external_paths' => [['label' => 'notes', 'path' => $extDir]],
    ]);

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $files = $action->handle($this->tmpDir, projectId: $project->id);

    $paths = collect($files)->pluck('path')->all();
    expect($paths)->toContain('file.txt');
    expect($paths)->toContain('external/notes/note.md');

    $external = collect($files)->firstWhere('path', 'external/notes/note.md');
    expect($external['isExternal'])->toBeTrue();
    expect($external['externalAbsolutePath'])->toEndWith('/note.md');
});

test('typed changeset includes external entries in working-tree mode', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $extDir = $this->createTempDirectory('rfa_changeset_ext_');
    File::put($extDir.'/note.md', "external content\n");

    $project = Project::factory()->create([
        'path' => $this->tmpDir,
        'git_common_dir' => $this->tmpDir.'/.git',
        'external_paths' => [['label' => 'notes', 'path' => $extDir]],
    ]);

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));
    $changeset = $action->changeset($this->tmpDir, projectId: $project->id);

    $paths = collect($changeset->files)->pluck('path')->all();
    expect($paths)->toContain('file.txt')
        ->and($paths)->toContain('external/notes/note.md');
});

test('hides external entries when the diff target is an immutable commit range', function () {
    $extDir = $this->createTempDirectory('rfa_filelist_ext_immut_');
    File::put($extDir.'/note.md', "external\n");

    $project = Project::factory()->create([
        'path' => $this->tmpDir,
        'git_common_dir' => $this->tmpDir.'/.git',
        'external_paths' => [['label' => 'notes', 'path' => $extDir]],
    ]);

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class), app(ReviewConfigService::class));

    $files = $action->handle(
        $this->tmpDir,
        projectId: $project->id,
        target: DiffTarget::range('HEAD', 'HEAD'),
    );

    expect(collect($files)->pluck('path')->all())
        ->not->toContain('external/notes/note.md');
});

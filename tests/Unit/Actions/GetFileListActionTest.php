<?php

use App\Actions\GetFileListAction;
use App\DTOs\DiffTarget;
use App\Models\Project;
use App\Services\ExternalFilesService;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
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

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class));
    $files = $action->handle($this->tmpDir);

    expect($files)->toHaveCount(1);
    expect($files[0])->toHaveKeys(['id', 'path', 'status', 'additions', 'deletions', 'isBinary', 'isUntracked']);
    expect($files[0]['id'])->toStartWith('file-');
    expect($files[0]['path'])->toBe('file.txt');
});

test('clears cache by default', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class));
    $files = $action->handle($this->tmpDir);

    $cacheKey = DiffCacheKey::for($this->tmpDir, $files[0]['id']);
    Cache::put($cacheKey, 'stale', 60);

    $action->handle($this->tmpDir);

    expect(Cache::has($cacheKey))->toBeFalse();
});

test('preserves cache when clearCache is false', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class));
    $files = $action->handle($this->tmpDir, clearCache: false);

    $cacheKey = DiffCacheKey::for($this->tmpDir, $files[0]['id']);
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

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class));
    $files = $action->handle($this->tmpDir, projectId: $project->id);

    $paths = collect($files)->pluck('path')->all();
    expect($paths)->toContain('file.txt');
    expect($paths)->toContain('external/notes/note.md');

    $external = collect($files)->firstWhere('path', 'external/notes/note.md');
    expect($external['isExternal'])->toBeTrue();
    expect($external['externalAbsolutePath'])->toEndWith('/note.md');
});

test('hides external entries when the diff target is an immutable commit range', function () {
    $extDir = $this->createTempDirectory('rfa_filelist_ext_immut_');
    File::put($extDir.'/note.md', "external\n");

    $project = Project::factory()->create([
        'path' => $this->tmpDir,
        'git_common_dir' => $this->tmpDir.'/.git',
        'external_paths' => [['label' => 'notes', 'path' => $extDir]],
    ]);

    $action = new GetFileListAction(new GitDiffService(new GitProcessService, new IgnoreService), app(ExternalFilesService::class));

    $files = $action->handle(
        $this->tmpDir,
        projectId: $project->id,
        target: DiffTarget::range('HEAD', 'HEAD'),
    );

    foreach ($files as $file) {
        expect($file['path'])->not->toStartWith('external/');
    }
});

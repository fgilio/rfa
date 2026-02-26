<?php

use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Models\Project;
use App\Services\GitDiffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false],
        ['id' => 'def456', 'path' => 'src/Bar.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 3, 'deletions' => 1, 'isBinary' => false, 'isUntracked' => false],
    ];

    $this->project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test Project',
        'path' => '/tmp/repo',
        'git_common_dir' => '/tmp/repo/.git',
        'branch' => 'main',
        'global_gitignore_path' => '/tmp/test-global-gitignore',
        'respect_global_gitignore' => true,
    ]);

    $project = $this->project;
    $files = $this->files;

    app()->instance('test.captured_gitignore_paths', collect());

    app()->bind(ResolveProjectAction::class, fn () => new class($project)
    {
        public function __construct(private Project $project) {}

        public function handle(string $slug): array
        {
            return $this->project->toArray();
        }
    });

    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null): array
        {
            app('test.captured_gitignore_paths')->push($globalGitignorePath);

            return $this->files;
        }
    });

    app()->bind(RestoreSessionAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $currentFiles, ?int $projectId = null): array
        {
            return ['comments' => [], 'viewedFiles' => [], 'globalComment' => ''];
        }
    });

    app()->bind(SaveSessionAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $comments, array $viewedFiles, string $globalComment, ?int $projectId = null): void {}
    });

    // Prevent backfill from calling real git
    app()->bind(GitDiffService::class, fn () => new class
    {
        public function resolveGlobalExcludesFile(string $repoPath): ?string
        {
            return null;
        }
    });
});

test('toggleViewed updates viewedFiles state', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-viewed', filePath: 'src/Foo.php');

    expect($component->get('viewedFiles'))->toBe(['src/Foo.php']);
});

test('toggleViewed skips parent re-render', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-viewed', filePath: 'src/Foo.php');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();
});

// -- Global gitignore toggle --

test('updatedRespectGlobalGitignore persists setting to database', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('respectGlobalGitignore', false);

    expect($this->project->fresh()->respect_global_gitignore)->toBeFalse();
});

test('updatedRespectGlobalGitignore does not skip render', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('respectGlobalGitignore', false);

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeFalsy();
});

test('updatedRespectGlobalGitignore passes null gitignore path when disabled', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('respectGlobalGitignore', false);

    $captured = app('test.captured_gitignore_paths');

    // First call is from mount (with path), second from toggle (null)
    expect($captured->last())->toBeNull();
});

test('updatedRespectGlobalGitignore passes gitignore path when re-enabled', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('respectGlobalGitignore', false)
        ->set('respectGlobalGitignore', true);

    $captured = app('test.captured_gitignore_paths');

    // Last call should pass the path since toggle is back on
    expect($captured->last())->toBe('/tmp/test-global-gitignore');
});

test('mount passes gitignore path when respectGlobalGitignore is true', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project']);

    $captured = app('test.captured_gitignore_paths');

    expect($captured->first())->toBe('/tmp/test-global-gitignore');
});

test('mount backfills null gitignore path from git config', function () {
    $this->project->update(['global_gitignore_path' => null]);

    $resolvedPath = '/home/user/.gitignore_global';

    // Override GitDiffService mock to return a path
    app()->bind(GitDiffService::class, fn () => new class($resolvedPath)
    {
        public function __construct(private string $path) {}

        public function resolveGlobalExcludesFile(string $repoPath): ?string
        {
            return $this->path;
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);

    expect($component->get('globalGitignorePath'))->toBe($resolvedPath);
    expect($this->project->fresh()->global_gitignore_path)->toBe($resolvedPath);
});

test('mount backfills empty string gitignore path from git config', function () {
    $this->project->update(['global_gitignore_path' => '']);

    $resolvedPath = '/home/user/.gitignore_global';

    app()->bind(GitDiffService::class, fn () => new class($resolvedPath)
    {
        public function __construct(private string $path) {}

        public function resolveGlobalExcludesFile(string $repoPath): ?string
        {
            return $this->path;
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);

    expect($component->get('globalGitignorePath'))->toBe($resolvedPath);
    expect($this->project->fresh()->global_gitignore_path)->toBe($resolvedPath);
});

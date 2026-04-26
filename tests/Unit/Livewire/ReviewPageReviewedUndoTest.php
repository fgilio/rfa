<?php

declare(strict_types=1);

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\SessionStateAction;
use App\DTOs\DiffTarget;
use App\Models\Project;
use App\Models\ReviewedFile;
use App\Services\GitFileContentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->files = [
        ['id' => 'id-foo', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false],
        ['id' => 'id-bar', 'path' => 'src/Bar.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 3, 'deletions' => 1, 'isBinary' => false, 'isUntracked' => false],
        ['id' => 'id-baz', 'path' => 'src/Baz.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 1, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false],
        ['id' => 'id-qux', 'path' => 'src/Qux.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 1, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false],
        ['id' => 'id-zap', 'path' => 'src/Zap.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 1, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false],
        ['id' => 'id-six', 'path' => 'src/Six.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 1, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false],
    ];

    $this->project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test Project',
        'path' => '/tmp/repo',
        'git_common_dir' => '/tmp/repo/.git',
        'branch' => 'main',
        'global_gitignore_path' => null,
        'respect_global_gitignore' => true,
    ]);

    $project = $this->project;
    $files = $this->files;

    app()->bind(ResolveProjectAction::class, fn () => new class($project)
    {
        public function __construct(private Project $project) {}

        public function handle(string $slug, bool $touch = false): ?array
        {
            return $this->project->toArray();
        }
    });

    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            return $this->files;
        }
    });

    app()->bind(SessionStateAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, ?DiffTarget $target = null): array
        {
            return ['comments' => [], 'reviewedFiles' => [], 'globalComment' => '', 'orphanedPaths' => []];
        }

        public function saveGlobalNote(string $repoPath, string $globalComment, ?int $projectId = null): void {}
    });

    $gitFileContentMock = Mockery::mock(GitFileContentService::class);
    $gitFileContentMock->shouldReceive('hashAt')->andReturn('mock-hash');
    app()->instance(GitFileContentService::class, $gitFileContentMock);

    app()->bind(BackfillGlobalGitignoreAction::class, fn () => new class
    {
        public function handle(int $projectId, string $repoPath): ?string
        {
            return null;
        }
    });
});

// -- Undo toast for mark-reviewed --

test('marking a file reviewed dispatches undo-available with mark-reviewed payload', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->assertDispatched('undo-available', function (string $event, array $params) {
            return $params['type'] === 'mark-reviewed'
                && $params['payload'] === ['filePaths' => ['src/Foo.php']]
                && str_contains($params['message'], 'Foo.php');
        });
});

test('un-marking a file does not dispatch undo-available', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->assertDispatched('undo-available');

    // The second dispatch is its own request; effects reset, and the un-mark must
    // produce no new undo-available dispatch.
    $component->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->assertNotDispatched('undo-available');

    expect($component->get('reviewedFiles'))->toBe([]);
});

test('undo mark-reviewed restores file to unreviewed state', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

    expect($component->get('reviewedFiles'))->toHaveKey('src/Foo.php');

    $component->call('undo', 'mark-reviewed', ['filePaths' => ['src/Foo.php']]);

    expect($component->get('reviewedFiles'))->not->toHaveKey('src/Foo.php');
    expect(ReviewedFile::where('file_path', 'src/Foo.php')->count())->toBe(0);
});

test('undo mark-reviewed dispatches reviewed-files-reverted with affected file ids', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Bar.php')
        ->call('undo', 'mark-reviewed', ['filePaths' => ['src/Foo.php', 'src/Bar.php']])
        ->assertDispatched('reviewed-files-reverted', function (string $event, array $params) {
            return collect($params['fileIds'])->sort()->values()->all() === ['id-bar', 'id-foo'];
        });
});

test('undo mark-reviewed silently ignores unknown paths', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->call('undo', 'mark-reviewed', ['filePaths' => ['src/Nonexistent.php']]);

    expect($component->get('reviewedFiles'))->toBe([]);
});

// -- Recently reviewed list --

test('marking a file pushes its id onto recentlyReviewedIds (MRU front)', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Bar.php');

    expect($component->get('recentlyReviewedIds'))->toBe(['id-bar', 'id-foo']);
});

test('recentlyReviewedIds caps at 5 (oldest evicted)', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Bar.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Baz.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Qux.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Zap.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Six.php');

    $ids = $component->get('recentlyReviewedIds');

    expect($ids)->toHaveCount(5);
    expect($ids)->toBe(['id-six', 'id-zap', 'id-qux', 'id-baz', 'id-bar']);
    expect($ids)->not->toContain('id-foo');
});

test('un-marking a file removes it from recentlyReviewedIds', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Bar.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

    expect($component->get('recentlyReviewedIds'))->toBe(['id-bar']);
});

test('undo mark-reviewed removes reverted ids from recentlyReviewedIds', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Bar.php')
        ->call('undo', 'mark-reviewed', ['filePaths' => ['src/Foo.php']]);

    expect($component->get('recentlyReviewedIds'))->toBe(['id-bar']);
});

test('clearRecentlyReviewed empties the list without un-marking files', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Bar.php')
        ->call('clearRecentlyReviewed');

    expect($component->get('recentlyReviewedIds'))->toBe([]);
    expect($component->get('reviewedFiles'))->toHaveKeys(['src/Foo.php', 'src/Bar.php']);
});

test('marking the same file twice does not duplicate in recentlyReviewedIds', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

    expect($component->get('recentlyReviewedIds'))->toBe(['id-foo']);
});

test('toggleReviewed still skips parent re-render after these changes', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();
});

test('clearRecentlyReviewed skips parent re-render', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->call('clearRecentlyReviewed');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();
});

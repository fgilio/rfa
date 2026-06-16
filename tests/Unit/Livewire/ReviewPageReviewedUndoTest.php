<?php

declare(strict_types=1);

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\GetFileListAction;
use App\Actions\GroupReviewFilesAction;
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

function renderedIslandFragments(mixed $component, string $name): string
{
    return collect($component->effects['islandFragments'] ?? [])
        ->filter(fn (string $fragment): bool => str_contains($fragment, "name={$name}|"))
        ->implode("\n");
}

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
    $gitFileContentMock->shouldReceive('hashForSource')->andReturn('mock-hash');
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

    $component->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->assertNotDispatched('undo-available');

    expect($component->get('reviewedFiles'))->toBe([]);
});

test('un-marking a file dispatches reviewed-files-reverted so DiffFile + sidebar mirror flip in lockstep', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->assertDispatched('reviewed-files-reverted', function (string $event, array $params) {
            return $params['fileIds'] === ['id-foo'];
        });
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

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue()
        ->and(renderedIslandFragments($component, 'reviewed-summary'))->toContain('1/6 reviewed')
        ->and(renderedIslandFragments($component, 'file-list'))->toContain('Un-mark as reviewed')
        ->and(renderedIslandFragments($component, 'source-diff-list'))->toBe('');
});

test('toggleReviewed renders source diff list island when hide-reviewed visibility changes', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->call('hideReviewedFiles')
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue()
        ->and($component->instance()->reviewState()->isFileVisible('id-foo'))->toBeFalse()
        ->and($component->instance()->reviewState()->isFileVisible('id-bar'))->toBeTrue()
        ->and(renderedIslandFragments($component, 'source-diff-list'))->toContain('wire:key="id-bar-')
        ->and(renderedIslandFragments($component, 'source-diff-list'))->not->toContain('wire:key="id-foo-');
});

test('hide-reviewed actions render diff-file children from fresh review state', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->assertSeeHtml('wire:key="id-foo-')
        ->assertSeeHtml('wire:key="id-bar-');

    $component->call('hideReviewedFiles');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue()
        ->and(renderedIslandFragments($component, 'reviewed-summary'))->toContain('aria-label="Show all files"')
        ->and(renderedIslandFragments($component, 'source-diff-list'))->not->toContain('wire:key="id-foo-')
        ->and(renderedIslandFragments($component, 'source-diff-list'))->toContain('wire:key="id-bar-');

    $component->call('showAllFiles');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue()
        ->and(renderedIslandFragments($component, 'reviewed-summary'))->toContain('aria-label="Hide reviewed"')
        ->and(renderedIslandFragments($component, 'source-diff-list'))->toContain('wire:key="id-foo-')
        ->and(renderedIslandFragments($component, 'source-diff-list'))->toContain('wire:key="id-bar-');
});

test('the reviewed counter reflects new marks in hide-reviewed mode', function () {
    // Hide-reviewed mode renders reviewed-state surfaces as explicit islands.
    // The reviewed-summary island must advance to 2/6, not stick at 1/6.
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->call('hideReviewedFiles')
        ->dispatch('toggle-reviewed', filePath: 'src/Bar.php');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue()
        ->and(renderedIslandFragments($component, 'reviewed-summary'))->toContain('2/6 reviewed');
});

test('marking a file broadcasts an authoritative file-reviewed-changed so DiffFile converges to the server state', function () {
    // The sidebar button bakes its optimistic reviewed value at island-render
    // time. A mark that lands on the server must echo the true state so a
    // mounted DiffFile cannot stay desynced from a stale optimistic dispatch
    // (e.g. a rapid double-click before the file-list island re-rendered).
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->assertDispatched('file-reviewed-changed', id: 'id-foo', reviewed: true);
});

test('toggleReviewed skips render when only a file filter is active (filter is reviewed-independent)', function () {
    // A path filter never hides a file for being reviewed — only Hide-reviewed
    // does (ReviewStateService::fileIsVisible). The server-visible list is
    // therefore unchanged by the toggle, so re-rendering would needlessly
    // re-hydrate every mounted diff-file child on a latency-sensitive path. The
    // toggle must still persist the reviewed state.
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('fileFilter', 'Foo')
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue()
        ->and($component->instance()->reviewState()->reviewedFileIds)->toBe(['id-foo']);
});

test('clearRecentlyReviewed skips parent re-render', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->call('clearRecentlyReviewed');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();
});

test('clearRecentlyReviewed renders sidebar file-list island while hide-reviewed group is visible', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->call('hideReviewedFiles')
        ->call('clearRecentlyReviewed');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue()
        ->and($component->get('recentlyReviewedIds'))->toBe([])
        ->and(renderedIslandFragments($component, 'file-list'))->not->toContain('data-testid="recently-reviewed-group"');
});

test('unmarkReviewed skips parent re-render to avoid 1+N child hydration', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php')
        ->call('undo', 'mark-reviewed', ['filePaths' => ['src/Foo.php']]);

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();
});

test('undo mark-reviewed deletes the DB row even when reviewedFiles map dropped the entry (refresh / hash drift)', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

    expect(ReviewedFile::where('file_path', 'src/Foo.php')->count())->toBe(1);

    // Simulate hash drift / refresh dropping the path from the in-memory map while
    // the DB row remains. The toast still holds the path and undo must clean up the row.
    $component->set('reviewedFiles', []);
    $component->call('undo', 'mark-reviewed', ['filePaths' => ['src/Foo.php']]);

    expect(ReviewedFile::where('file_path', 'src/Foo.php')->count())->toBe(0);
});

test('marking a review artifact does not consume a "Recently reviewed" slot', function () {
    // Bind a GroupReviewFilesAction that excludes notes.json so Foo is the only
    // source file. The page-level $files still includes the artifact, so
    // toggleReviewed must look it up via $sourceFiles.
    app()->bind(GroupReviewFilesAction::class, fn () => new class
    {
        public function handle(array $files): array
        {
            return array_values(array_filter(
                $files,
                fn (array $f) => ! str_ends_with($f['path'], 'notes.json'),
            ));
        }
    });

    $files = [
        ['id' => 'id-foo', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 1, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false],
        ['id' => 'id-pair', 'path' => 'reviews/notes.json', 'status' => 'added', 'oldPath' => null, 'additions' => 1, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false],
    ];
    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            return $this->files;
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'reviews/notes.json');

    expect($component->get('recentlyReviewedIds'))->toBe([]);
    expect($component->get('reviewedFiles'))->toHaveKey('reviews/notes.json');
});

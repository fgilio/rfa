<?php

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\CleanExpiredTrashAction;
use App\Actions\DiscardFileChangesAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Actions\ScanReviewFilesAction;
use App\DTOs\DiffTarget;
use App\Models\Project;
use App\Models\TrashedFile;
use App\Services\GitFileContentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

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
            app('test.captured_gitignore_paths')->push($globalGitignorePath);

            return $this->files;
        }
    });

    app()->bind(RestoreSessionAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, ?DiffTarget $target = null): array
        {
            return ['comments' => [], 'reviewedFiles' => [], 'globalComment' => '', 'orphanedPaths' => []];
        }
    });

    app()->bind(SaveSessionAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $globalComment, ?int $projectId = null): void {}
    });

    // Mock GitFileContentService to avoid real git calls
    $gitFileContentMock = Mockery::mock(GitFileContentService::class);
    $gitFileContentMock->shouldReceive('hashAt')->andReturn('mock-hash');
    app()->instance(GitFileContentService::class, $gitFileContentMock);

    // Prevent backfill from calling real git
    app()->bind(BackfillGlobalGitignoreAction::class, fn () => new class
    {
        public function handle(int $projectId, string $repoPath): ?string
        {
            return null;
        }
    });
});

test('toggleReviewed updates reviewedFiles state', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

    expect($component->get('reviewedFiles'))->toBe(['src/Foo.php' => 'mock-hash']);
});

test('toggleReviewed skips parent re-render', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('toggle-reviewed', filePath: 'src/Foo.php');

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

    app()->bind(BackfillGlobalGitignoreAction::class, fn () => new class($resolvedPath)
    {
        public function __construct(private string $path) {}

        public function handle(int $projectId, string $repoPath): ?string
        {
            return $this->path;
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);

    expect($component->get('globalGitignorePath'))->toBe($resolvedPath);
});

test('mount backfills empty string gitignore path from git config', function () {
    $this->project->update(['global_gitignore_path' => '']);

    $resolvedPath = '/home/user/.gitignore_global';

    app()->bind(BackfillGlobalGitignoreAction::class, fn () => new class($resolvedPath)
    {
        public function __construct(private string $path) {}

        public function handle(int $projectId, string $repoPath): ?string
        {
            return $this->path;
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);

    expect($component->get('globalGitignorePath'))->toBe($resolvedPath);
});

// -- Submit review refreshes file list --

test('submitReview refreshes file list and populates reviewPairs', function () {
    $basename = '20260227_173000_comments_abcd1234';
    $reviewPair = [
        'id' => 'review-'.hash('xxh128', $basename),
        'basename' => $basename,
        'displayName' => 'Feb 27, 5:30 PM',
        'jsonFile' => ['id' => 'file-'.hash('xxh128', ".rfa/{$basename}.json"), 'path' => ".rfa/{$basename}.json", 'status' => 'added', 'oldPath' => null, 'additions' => 0, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => true, 'isImage' => false, 'lastModified' => null, 'isSymlink' => false, 'symlinkTarget' => null, 'fileSize' => null],
        'mdFile' => ['id' => 'file-'.hash('xxh128', ".rfa/{$basename}.md"), 'path' => ".rfa/{$basename}.md", 'status' => 'added', 'oldPath' => null, 'additions' => 0, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => true, 'isImage' => false, 'lastModified' => null, 'isSymlink' => false, 'symlinkTarget' => null, 'fileSize' => null],
        'createdAt' => '2026-02-27T17:30:00+00:00',
        'createdAtHuman' => '1 month ago',
    ];

    $counter = (object) ['value' => 0];
    app()->bind(ScanReviewFilesAction::class, function () use ($reviewPair, $counter) {
        return new class($reviewPair, $counter)
        {
            public function __construct(private array $reviewPair, private object $counter) {}

            public function handle(string $repoPath): array
            {
                $this->counter->value++;

                // First call (mount): empty. Subsequent calls: review pair exists.
                return $this->counter->value <= 1 ? [] : [$this->reviewPair];
            }
        };
    });

    app()->bind(ExportReviewAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $comments, string $globalComment, array $files): array
        {
            return ['json' => '/tmp/review.json', 'md' => '/tmp/review.md', 'clipboard' => 'review exported'];
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);
    expect($component->get('reviewPairs'))->toBeEmpty();

    $component
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Test comment')
        ->call('submitReview');

    expect($component->get('reviewPairs'))->toHaveCount(1);
    expect($component->get('submitted'))->toBeTrue();
});

// -- Clear all comments --

test('clearAllComments empties comments and saves', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Comment 1')
        ->dispatch('add-comment', fileId: 'def456', side: 'right', startLine: 2, endLine: 2, body: 'Comment 2')
        ->call('clearAllComments');

    expect($component->get('comments'))->toBeEmpty();
});

test('clearAllComments dispatches comment-updated to affected files', function () {
    // Set comments directly to avoid addComment also dispatching comment-updated
    // (Livewire's assertDispatched callback only checks the first matching event name)
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);
    $component->set('comments', [
        ['id' => 'c-1', 'fileId' => 'abc123', 'file' => 'src/Foo.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'Comment 1', 'isDraft' => false],
        ['id' => 'c-2', 'fileId' => 'def456', 'file' => 'src/Bar.php', 'side' => 'right', 'startLine' => 2, 'endLine' => 2, 'body' => 'Comment 2', 'isDraft' => false],
    ]);
    $component->call('clearAllComments');

    $component->assertDispatched('comment-updated', fn ($name, $params) => $params['fileId'] === 'abc123' && $params['comments'] === []);
});

test('clearAllComments dispatches undo-available with all comments', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Comment 1')
        ->dispatch('add-comment', fileId: 'def456', side: 'right', startLine: 2, endLine: 2, body: 'Comment 2')
        ->call('clearAllComments');

    $component->assertDispatched('undo-available', fn ($name, $params) => $params['type'] === 'clear-all' && count($params['payload']) === 2);
});

test('clearAllComments no-ops when empty', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->call('clearAllComments');

    $component->assertNotDispatched('undo-available');
});

test('clearAllComments skips render', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Comment 1')
        ->call('clearAllComments');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();
});

// -- Restore comments --

test('restoreComments re-adds deleted comments', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Comment 1');

    $comments = $component->get('comments');

    $component->call('clearAllComments');
    expect($component->get('comments'))->toBeEmpty();

    $component->call('restoreComments', $comments);
    expect($component->get('comments'))->toHaveCount(1);
    expect($component->get('comments.0.body'))->toBe('Comment 1');
});

test('restoreComments skips duplicate IDs', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Comment 1');

    $comments = $component->get('comments');

    // Try to restore the same comment that already exists
    $component->call('restoreComments', $comments);
    expect($component->get('comments'))->toHaveCount(1);
});

test('restoreComments dispatches comment-updated to affected files', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Comment 1');

    $comments = $component->get('comments');

    $component->call('clearAllComments')
        ->call('restoreComments', $comments);

    // comment-updated dispatched once during clearAll (empty), then again during restore (with comment)
    $component->assertDispatched('comment-updated', fn ($name, $params) => $params['fileId'] === 'abc123' && count($params['comments']) === 1);
});

test('restoreComments skips render', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Comment 1');

    $comments = $component->get('comments');

    $component->call('clearAllComments')
        ->call('restoreComments', $comments);

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();
});

// -- Delete comment undo-available --

test('deleteComment dispatches undo-available with deleted comment', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Comment 1');

    $commentId = $component->get('comments.0.id');

    $component->dispatch('delete-comment', commentId: $commentId);

    $component->assertDispatched('undo-available', fn ($name, $params) => $params['type'] === 'delete'
        && count($params['payload']) === 1
        && $params['payload'][0]['id'] === $commentId
    );
});

// -- Discard file undo-available --

test('discardFileChanges dispatches undo-available with file name', function () {
    $trashRecord = TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'src/Foo.php',
        'file_status' => 'modified',
        'expires_at' => now()->addMinutes(30),
    ]);

    app()->bind(DiscardFileChangesAction::class, fn () => new class($trashRecord)
    {
        public function __construct(private TrashedFile $record) {}

        public function handle(string $repoPath, string $path, string $status, int $projectId, ?string $oldPath = null, bool $isUntracked = false, bool $isSymlink = false, array $comments = []): TrashedFile
        {
            return $this->record;
        }
    });

    app()->bind(CleanExpiredTrashAction::class, fn () => new class
    {
        public function handle(int $projectId): array
        {
            return [];
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('discard-file', fileId: 'abc123');

    $component->assertDispatched('undo-available', fn ($name, $params) => $params['type'] === 'discard'
        && $params['payload'] === $trashRecord->id
        && str_contains($params['message'], 'Foo.php')
    );
});

test('discardFileChanges includes comment count in undo message', function () {
    $trashRecord = TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'src/Foo.php',
        'file_status' => 'modified',
        'expires_at' => now()->addMinutes(30),
    ]);

    app()->bind(DiscardFileChangesAction::class, fn () => new class($trashRecord)
    {
        public function __construct(private TrashedFile $record) {}

        public function handle(string $repoPath, string $path, string $status, int $projectId, ?string $oldPath = null, bool $isUntracked = false, bool $isSymlink = false, array $comments = []): TrashedFile
        {
            return $this->record;
        }
    });

    app()->bind(CleanExpiredTrashAction::class, fn () => new class
    {
        public function handle(int $projectId): array
        {
            return [];
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Review note')
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 5, endLine: 5, body: 'Another note')
        ->dispatch('discard-file', fileId: 'abc123');

    $component->assertDispatched('undo-available', fn ($name, $params) => $params['type'] === 'discard'
        && str_contains($params['message'], 'Foo.php')
        && str_contains($params['message'], '2 comments removed')
    );
});

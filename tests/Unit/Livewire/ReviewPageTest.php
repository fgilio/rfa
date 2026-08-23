<?php

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\CleanExpiredTrashAction;
use App\Actions\DeleteReviewFilesAction;
use App\Actions\DiscardFileChangesAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\ResolveRangeToWorkingAction;
use App\Actions\ScanReviewFilesAction;
use App\Actions\SessionStateAction;
use App\DTOs\DiffTarget;
use App\Enums\DiscardOperation;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use App\Models\TrashedFile;
use App\Services\GitFileContentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    app()->bind(SessionStateAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, ?DiffTarget $target = null): array
        {
            return ['comments' => [], 'reviewedFiles' => [], 'globalComment' => '', 'orphanedPaths' => []];
        }

        public function saveGlobalNote(string $repoPath, string $globalComment, ?int $projectId = null): void {}
    });

    // Mock GitFileContentService to avoid real git calls
    $gitFileContentMock = Mockery::mock(GitFileContentService::class);
    $gitFileContentMock->shouldReceive('hashForSource')->andReturn('mock-hash');
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

test('diff file lazy loads stay isolated to avoid Livewire max component payload', function () {
    $maxComponents = config('livewire.payload.max_components', 20);

    $files = collect(range(1, $maxComponents + 5))
        ->map(fn (int $index): array => [
            'id' => "file-{$index}",
            'path' => "src/File{$index}.php",
            'status' => 'modified',
            'oldPath' => null,
            'additions' => 1,
            'deletions' => 1,
            'isBinary' => false,
            'isUntracked' => false,
            'isImage' => false,
            'lastModified' => null,
            'isSymlink' => false,
            'symlinkTarget' => null,
            'fileSize' => null,
        ])
        ->all();

    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            return $this->files;
        }
    });

    $html = Livewire::test('pages::review-page', ['slug' => 'test-project'])->html();
    $lazyLoadCount = substr_count($html, '__lazyLoad');

    expect($lazyLoadCount)
        ->toBeGreaterThan($maxComponents)
        ->and($html)->not->toContain('lazyIsolated&quot;:false');
});

// -- File navigation shortcut hint --

test('shows the j/k navigation keycaps when more than one file is visible', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->assertSeeHtml('aria-label="Press j for the next file, k for the previous file"');
});

test('hides the j/k navigation keycaps when only one file is visible', function () {
    app()->bind(GetFileListAction::class, fn () => new class
    {
        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            return [
                ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false],
            ];
        }
    });

    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->assertDontSeeHtml('aria-label="Press j for the next file, k for the previous file"');
});

test('server file filter renders only matching diff-file children', function () {
    $files = collect(range(1, 25))
        ->map(fn (int $index): array => [
            'id' => "file-{$index}",
            'path' => "src/File{$index}.php",
            'status' => 'modified',
            'oldPath' => null,
            'additions' => 1,
            'deletions' => 1,
            'isBinary' => false,
            'isUntracked' => false,
            'isImage' => false,
            'lastModified' => null,
            'isSymlink' => false,
            'symlinkTarget' => null,
            'fileSize' => null,
        ])
        ->all();

    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            return $this->files;
        }
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('fileFilter', 'File25.php');

    expect($component->instance()->reviewState()->visibleFileEntries)->toBe([
        ['id' => 'file-25', 'path' => 'src/File25.php'],
    ])
        ->and(substr_count($component->html(), '__lazyLoad'))->toBe(1);
});

test('copyVisiblePaths copies only the filtered files under a partial filter', function () {
    // Bulk copy is server-owned: ReviewPage builds the clipboard text from its
    // authoritative visible set, so a partial filter copies exactly what the user
    // sees and never the files the filter hid.
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('fileFilter', 'Foo')
        ->call('copyVisiblePaths', 'relative')
        ->assertDispatched('copy-to-clipboard', text: 'src/Foo.php', toast: 'Copied relative path');
});

test('copyVisiblePaths formats bare names and absolute paths', function () {
    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->call('copyVisiblePaths', 'name')
        ->assertDispatched('copy-to-clipboard', text: "Foo.php\nBar.php", toast: 'Copied 2 file names');

    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->call('copyVisiblePaths', 'full')
        ->assertDispatched('copy-to-clipboard', text: "/tmp/repo/src/Foo.php\n/tmp/repo/src/Bar.php", toast: 'Copied 2 full paths');
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
        public function handle(string $repoPath, array $comments, string $globalComment, array $files, ?DiffTarget $target = null): array
        {
            return [
                'md' => '/tmp/review.md',
                'clipboard' => 'review exported',
                'submittedIds' => array_column($comments, 'id'),
            ];
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

test('deleting the submitted review resets the submit bar', function () {
    $basename = '20260227_173000_comments_abcd1234';

    app()->bind(ExportReviewAction::class, fn () => new class($basename)
    {
        public function __construct(private string $basename) {}

        public function handle(string $repoPath, array $comments, string $globalComment, array $files, ?DiffTarget $target = null): array
        {
            return [
                'md' => ".rfa/{$this->basename}.md",
                'clipboard' => 'review exported',
                'submittedIds' => array_column($comments, 'id'),
            ];
        }
    });
    app()->bind(DeleteReviewFilesAction::class, fn () => new class
    {
        public function handle(string $repoPath, array|string $basenames): void {}
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Test comment')
        ->call('submitReview');

    expect($component->get('submitted'))->toBeTrue();
    expect($component->get('submittedReviewBasename'))->toBe($basename);

    $component->call('deleteReviewPair', $basename);

    expect($component->get('submitted'))->toBeFalse();
    expect($component->get('exportResult'))->toBeNull();
    expect($component->get('submittedReviewBasename'))->toBeNull();
});

test('deleting a different review leaves the submit bar untouched', function () {
    $basename = '20260227_173000_comments_abcd1234';

    app()->bind(ExportReviewAction::class, fn () => new class($basename)
    {
        public function __construct(private string $basename) {}

        public function handle(string $repoPath, array $comments, string $globalComment, array $files, ?DiffTarget $target = null): array
        {
            return [
                'md' => ".rfa/{$this->basename}.md",
                'clipboard' => 'review exported',
                'submittedIds' => array_column($comments, 'id'),
            ];
        }
    });
    app()->bind(DeleteReviewFilesAction::class, fn () => new class
    {
        public function handle(string $repoPath, array|string $basenames): void {}
    });

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('add-comment', fileId: 'abc123', side: 'right', startLine: 1, endLine: 1, body: 'Test comment')
        ->call('submitReview')
        ->call('deleteReviewPair', '20251010_090000_comments_zzzz9999');

    expect($component->get('submitted'))->toBeTrue();
    expect($component->get('submittedReviewBasename'))->toBe($basename);
});

test('startNewReview returns the submit bar to the input state', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'test-project']);
    $component->set('submitted', true);
    $component->set('exportResult', 'address my comments on these changes in @.rfa/foo.md');

    $component->call('startNewReview');

    expect($component->get('submitted'))->toBeFalse();
    expect($component->get('exportResult'))->toBeNull();
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
    $comments = [
        ['id' => 'c-1', 'fileId' => 'abc123', 'file' => 'src/Foo.php', 'side' => 'right', 'startLine' => 1, 'endLine' => 1, 'body' => 'Comment 1', 'isDraft' => false],
        ['id' => 'c-2', 'fileId' => 'def456', 'file' => 'src/Bar.php', 'side' => 'right', 'startLine' => 2, 'endLine' => 2, 'body' => 'Comment 2', 'isDraft' => false],
    ];
    collect($comments)->each(fn (array $comment) => Comment::create([
        'id' => $comment['id'],
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'working',
        'file_path' => $comment['file'],
        'side' => $comment['side'],
        'start_line' => $comment['startLine'],
        'end_line' => $comment['endLine'],
        'body' => $comment['body'],
    ]));
    $component->set('comments', $comments);
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
        && $params['payload'][0]['comment']['id'] === $commentId
    );
});

test('reply events inject the trusted UI author and update only the matching thread', function () {
    $root = Comment::create([
        'id' => 'c-reply-root',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'working',
        'file_path' => 'src/Foo.php',
        'side' => 'right',
        'start_line' => 1,
        'end_line' => 1,
        'body' => 'Root',
    ]);
    $comments = [
        [
            'id' => $root->id,
            'fileId' => 'abc123',
            'file' => 'src/Foo.php',
            'side' => 'right',
            'startLine' => 1,
            'endLine' => 1,
            'body' => 'Root',
            'replies' => [],
        ],
        [
            'id' => 'c-view-only',
            'fileId' => 'def456',
            'file' => 'src/Bar.php',
            'side' => 'right',
            'body' => 'Untouched',
            'replies' => [],
        ],
    ];

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('comments', $comments)
        ->dispatch('add-comment-reply', commentId: $root->id, body: 'UI reply');

    $reply = CommentReply::query()->sole();

    expect($reply->author_type->value)->toBe('human')
        ->and($reply->author_key)->toBe('rfa-ui')
        ->and($component->get('comments.0.replies.0.body'))->toBe('UI reply')
        ->and($component->get('comments.1.replies'))->toBe([])
        ->and(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();

    $component->assertDispatched('comment-thread-updated', fn ($name, $params) => $params['commentId'] === $root->id
        && $params['fileId'] === 'abc123'
        && count($params['replies']) === 1);
});

test('replies to submitted drawer roots persist while absent from page state', function () {
    $root = Comment::create([
        'id' => 'c-submitted-reply',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'working',
        'file_path' => 'src/Foo.php',
        'side' => 'right',
        'body' => 'Submitted root',
        'submitted_at' => now(),
    ]);

    $component = Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->assertSet('comments', [])
        ->dispatch('add-comment-reply', commentId: $root->id, body: 'After submit');

    expect(CommentReply::query()->sole()->body)->toBe('After submit')
        ->and($component->get('comments'))->toBe([]);

    $component->assertDispatched('comment-thread-updated', fn ($name, $params) => $params['commentId'] === $root->id
        && $params['fileId'] === null);
});

test('reply deletion can be undone through the page coordinator', function () {
    $root = Comment::create([
        'id' => 'c-reply-undo',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'working',
        'file_path' => 'src/Foo.php',
        'side' => 'right',
        'body' => 'Root',
    ]);
    $reply = CommentReply::factory()->for($root)->create(['id' => 'r-page-undo']);
    $payload = App\DTOs\CommentReply::fromArray($reply->toArray())->toArray();

    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->set('comments', [[
            'id' => $root->id,
            'fileId' => 'abc123',
            'file' => 'src/Foo.php',
            'side' => 'right',
            'body' => 'Root',
            'replies' => [$payload],
        ]])
        ->dispatch('delete-comment-reply', replyId: $reply->id)
        ->assertDispatched('undo-available', type: 'delete-reply', message: 'Reply deleted')
        ->call('undo', 'delete-reply', $payload);

    expect(CommentReply::query()->find('r-page-undo'))->not->toBeNull();
});

test('reply undo fails softly when its root no longer exists', function () {
    $root = Comment::create([
        'id' => 'c-reply-undo-missing-root',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'working',
        'file_path' => 'src/Foo.php',
        'side' => 'right',
        'body' => 'Root',
    ]);
    $reply = CommentReply::factory()->for($root)->create(['id' => 'r-orphaned-undo']);
    $payload = App\DTOs\CommentReply::fromArray($reply->toArray())->toArray();

    $reply->delete();
    $root->delete();

    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->call('undo', 'delete-reply', $payload)
        ->assertDispatched(
            'toast-show',
            fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'warning'
                && ($params['slots']['text'] ?? null) === 'Reply could not be restored because its comment no longer exists.',
        );

    expect(CommentReply::query()->find('r-orphaned-undo'))->toBeNull();
});

// -- Discard file undo-available --

test('discardFileChanges dispatches undo-available with file name', function () {
    $trashRecord = TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'src/Foo.php',
        'operation' => DiscardOperation::ModificationReverted,
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
        'operation' => DiscardOperation::ModificationReverted,
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

test('discardFileChanges is a no-op for external files', function () {
    $externalFiles = [
        ['id' => 'ext789', 'path' => 'external/notes/draft.md', 'status' => 'added', 'oldPath' => null, 'additions' => 10, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false, 'isExternal' => true, 'externalAbsolutePath' => '/tmp/notes/draft.md'],
    ];

    app()->bind(GetFileListAction::class, fn () => new class($externalFiles)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            return $this->files;
        }
    });

    app()->bind(DiscardFileChangesAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, string $status, int $projectId, ?string $oldPath = null, bool $isUntracked = false, bool $isSymlink = false, array $comments = []): TrashedFile
        {
            throw new RuntimeException('DiscardFileChangesAction should not be called for external files');
        }
    });

    Livewire::test('pages::review-page', ['slug' => 'test-project'])
        ->dispatch('discard-file', fileId: 'ext789')
        ->assertNotDispatched('undo-available');
});

test('discardFileChanges is a no-op in the entire-repo (since the beginning) view', function () {
    app()->bind(ResolveRangeToWorkingAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $from): DiffTarget
        {
            return DiffTarget::rangeToWorking($from);
        }
    });

    app()->bind(DiscardFileChangesAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, string $status, int $projectId, ?string $oldPath = null, bool $isUntracked = false, bool $isSymlink = false, array $comments = []): TrashedFile
        {
            throw new RuntimeException('DiscardFileChangesAction must not run in the entire-repo view');
        }
    });

    $component = Livewire::test('pages::review-page', [
        'slug' => 'test-project',
        'rangeFromWorking' => DiffTarget::EMPTY_TREE_HASH,
    ]);

    expect($component->get('isSinceBeginningView'))->toBeTrue();

    $component->dispatch('discard-file', fileId: 'abc123')
        ->assertNotDispatched('undo-available');
});

test('mount writes the project id to the active-project-id cache key for the menu handler', function () {
    Cache::forget('rfa.active-project-id');

    Livewire::test('pages::review-page', ['slug' => 'test-project']);

    expect(Cache::get('rfa.active-project-id'))->toBe($this->project->id);
});

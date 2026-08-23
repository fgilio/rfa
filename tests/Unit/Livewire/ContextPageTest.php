<?php

use App\Actions\ContextCommentWorkflowAction;
use App\Actions\DiscoverAgentContextFilesAction;
use App\Actions\ExportContextFeedbackAction;
use App\Actions\LoadContextCommentsAction;
use App\Actions\PersistProjectViewAction;
use App\Actions\ResolveProjectAction;
use App\Actions\ResolveStartupRouteAction;
use App\DTOs\SavedView;
use App\Enums\ContextCommentRejection;
use App\Events\HardReloadShortcutPressed;
use App\Events\RefreshShortcutPressed;
use App\Exceptions\ContextCommentRejectedException;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test Project',
        'path' => '/tmp/repo',
        'git_common_dir' => '/tmp/repo/.git',
        'branch' => 'main',
    ]);

    $project = $this->project;

    app()->bind(ResolveProjectAction::class, fn () => new class($project)
    {
        public function __construct(private Project $project) {}

        public function handle(string $slug, bool $touch = false): ?array
        {
            return $this->project->toArray();
        }
    });

    $this->contextFileDiscoveryFake = new class
    {
        public int $callCount = 0;

        public function handle(string $repoPath): array
        {
            $this->callCount++;

            return [];
        }
    };

    app()->instance(DiscoverAgentContextFilesAction::class, $this->contextFileDiscoveryFake);

    app()->bind(LoadContextCommentsAction::class, fn () => new class
    {
        public function handle(string $repoPath, ?int $projectId): array
        {
            return [];
        }
    });
});

test('mount records the project entry for the menu handler and for startup', function () {
    Cache::forget('rfa.active-project-id');
    app(ResolveStartupRouteAction::class)->forgetLastOpened();

    Livewire::test('pages::context-page', ['slug' => 'test-project']);

    expect(Cache::get('rfa.active-project-id'))->toBe($this->project->id)
        ->and(app(ResolveStartupRouteAction::class)->lastOpenedSlug())->toBe('test-project');
});

test('mount makes startup restore the project the user was last in on Context', function () {
    Project::create([
        'slug' => 'other-project',
        'name' => 'Other Project',
        'path' => '/tmp/other-repo',
        'git_common_dir' => '/tmp/other-repo/.git',
        'branch' => 'main',
    ]);
    app(ResolveStartupRouteAction::class)->rememberLastOpened('other-project');

    Livewire::test('pages::context-page', ['slug' => 'test-project']);

    // Stands in for the view persistence mount defers until after the response.
    app(PersistProjectViewAction::class)->handle($this->project->id, $this->project->path, SavedView::context());

    expect(app(ResolveStartupRouteAction::class)->handle())
        ->toBe(route('context-page', ['slug' => 'test-project']));
});

test('native refresh shortcut refreshes context files', function () {
    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->dispatch('native:App\\Events\\RefreshShortcutPressed', RefreshShortcutPressed::KEY);

    expect($this->contextFileDiscoveryFake->callCount)->toBe(2);
});

test('native hard reload shortcut requests a browser reload from context page', function () {
    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->dispatch('native:App\\Events\\HardReloadShortcutPressed', HardReloadShortcutPressed::KEY)
        ->assertDispatched('hard-reload-requested');
});

test('startNewFeedback clears the submission receipt and the globalComment field', fn () => Livewire::test('pages::context-page', ['slug' => 'test-project'])
    ->set('submissionReceipt', ['path' => '/tmp/repo/.rfa/feedback.md', 'clipboard' => 'improve the context files'])
    ->set('globalComment', 'leftover thoughts')
    ->call('startNewFeedback')
    ->assertSet('submissionReceipt', null)
    ->assertSet('globalComment', ''));

test('deleteComment dispatches an undo-available event with the deleted payload', function () {
    $comment = [
        'id' => 'c-context-undo-1',
        'fileId' => 'ctx-claude',
        'file' => 'CLAUDE.md',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'about to vanish',
        'originRef' => Comment::ORIGIN_CONTEXT,
        'fileContentHash' => null,
        'lineSnippet' => null,
        'isDraft' => false,
    ];

    Comment::create([
        'id' => $comment['id'],
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => Comment::ORIGIN_CONTEXT,
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'start_line' => 1,
        'end_line' => 1,
        'body' => 'about to vanish',
        'is_draft' => false,
    ]);

    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->set('comments', [$comment])
        ->call('deleteComment', $comment['id'])
        ->assertDispatched('undo-available', type: 'delete', message: 'Comment deleted');

    expect(Comment::find($comment['id']))->toBeNull();
});

test('undo of a delete restores the row and rehydrates the in-memory list', function () {
    $row = Comment::create([
        'id' => 'c-context-restore-1',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => Comment::ORIGIN_CONTEXT,
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'start_line' => 4,
        'end_line' => 4,
        'body' => 'restore me',
        'is_draft' => false,
    ]);

    $row->delete();
    expect(Comment::find($row->id))->toBeNull();

    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->call('undo', 'delete', [[
            'id' => $row->id,
            'fileId' => 'ctx-claude',
            'file' => 'CLAUDE.md',
            'side' => 'right',
            'startLine' => 4,
            'endLine' => 4,
            'body' => 'restore me',
            'isDraft' => false,
            'fileContentHash' => null,
            'lineSnippet' => null,
        ]]);

    $restored = Comment::find($row->id);
    expect($restored)->not->toBeNull();
    expect($restored->body)->toBe('restore me');
    expect($restored->origin_ref)->toBe(Comment::ORIGIN_CONTEXT);
});

test('clearAllComments dispatches a clear-all undo event with every removed row', function () {
    $rows = collect(['a', 'b'])->map(fn (string $suffix) => Comment::create([
        'id' => "c-context-clearall-{$suffix}",
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => Comment::ORIGIN_CONTEXT,
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'start_line' => 1,
        'end_line' => 1,
        'body' => "row {$suffix}",
        'is_draft' => false,
    ]))->all();

    $payload = collect($rows)->map(fn ($row) => [
        'id' => $row->id,
        'fileId' => 'ctx-claude',
        'file' => 'CLAUDE.md',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => $row->body,
        'isDraft' => false,
        'fileContentHash' => null,
        'lineSnippet' => null,
    ])->all();

    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->set('comments', $payload)
        ->call('clearAllComments')
        ->assertDispatched('undo-available', type: 'clear-all', message: 'Cleared 2 comments');

    expect(Comment::whereIn('id', array_column($payload, 'id'))->count())->toBe(0);
});

test('context reply events use the same trusted human workflow', function () {
    $root = Comment::create([
        'id' => 'c-context-reply',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => Comment::ORIGIN_CONTEXT,
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'body' => 'Root',
    ]);

    $component = Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->set('comments', [[
            'id' => $root->id,
            'fileId' => 'ctx-claude',
            'file' => 'CLAUDE.md',
            'side' => 'right',
            'body' => 'Root',
            'replies' => [],
        ]])
        ->dispatch('add-comment-reply', commentId: $root->id, body: 'Context reply');

    $reply = CommentReply::query()->sole();

    expect($reply->author_type->value)->toBe('human')
        ->and($reply->author_key)->toBe('rfa-ui')
        ->and($component->get('comments.0.replies.0.body'))->toBe('Context reply')
        ->and(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();

    $component->assertDispatched('comment-thread-updated', commentId: $root->id, fileId: 'ctx-claude');
});

test('addComment emits a canonical context.comment.written event with completed outcome', function () {
    app()->bind(ContextCommentWorkflowAction::class, fn () => new class
    {
        /** @return array<string, mixed> */
        public function handle(mixed ...$args): array
        {
            return [
                'id' => 'c1',
                'fileId' => 'file-1',
                'side' => 'file',
                'startLine' => null,
                'endLine' => null,
                'body' => 'hello',
                'isDraft' => false,
                'lineSnippet' => null,
            ];
        }
    });

    Log::spy();

    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->call('addComment', 'file-1', 'file', null, null, 'hello');

    Log::shouldHaveReceived('info')->once()->with('context.comment.written');
    expect(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.file_id'))->toBe('file-1')
        ->and(Context::get('rfa.is_draft'))->toBeFalse()
        ->and(Context::get('rfa.duration_ms'))->toBeInt();
});

test('addComment records a rejected outcome and keeps the diagnostic warning when the payload is rejected', function () {
    app()->bind(ContextCommentWorkflowAction::class, fn () => new class
    {
        public function handle(mixed ...$args): never
        {
            throw new ContextCommentRejectedException(ContextCommentRejection::UnknownFileId);
        }
    });

    Log::spy();

    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->call('addComment', 'nope', 'file', null, null, 'hello');

    Log::shouldHaveReceived('warning')->once()->with('context.comment.rejected', Mockery::type('array'));
    Log::shouldHaveReceived('info')->once()->with('context.comment.written');
    expect(Context::get('rfa.outcome'))->toBe('rejected')
        ->and(Context::get('rfa.reason'))->toBe(ContextCommentRejection::UnknownFileId->value);
});

test('addComment records a skipped outcome when the workflow produces no comment', function () {
    app()->bind(ContextCommentWorkflowAction::class, fn () => new class
    {
        public function handle(mixed ...$args): ?array
        {
            return null;
        }
    });

    Log::spy();

    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->call('addComment', 'file-1', 'file', null, null, '');

    Log::shouldHaveReceived('info')->once()->with('context.comment.written');
    expect(Context::get('rfa.outcome'))->toBe('skipped');
});

test('addComment records an error outcome and rethrows on unexpected failure', function () {
    app()->bind(ContextCommentWorkflowAction::class, fn () => new class
    {
        public function handle(mixed ...$args): never
        {
            throw new RuntimeException('boom');
        }
    });

    Log::spy();

    expect(fn () => Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->call('addComment', 'file-1', 'file', null, null, 'hello'))
        ->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('info')->once()->with('context.comment.written');
    expect(Context::get('rfa.outcome'))->toBe('error')
        ->and(Context::get('rfa.reason'))->toBe('comment_write_failed');
});

test('submitFeedback records the exported path and clipboard text as one receipt', function () {
    app()->bind(ExportContextFeedbackAction::class, fn () => new class
    {
        public function handle(string $repoPath, ?int $projectId, array $comments, string $globalComment): array
        {
            return [
                'md' => '/tmp/repo/.rfa/20260227_173000_comments_abcd1234.md',
                'clipboard' => 'improve the agent context files',
                'submittedIds' => [],
                'excludedComments' => [],
            ];
        }
    });

    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->set('globalComment', 'overall thoughts')
        ->call('submitFeedback')
        ->assertSet('submissionReceipt', [
            'path' => '/tmp/repo/.rfa/20260227_173000_comments_abcd1234.md',
            'clipboard' => 'improve the agent context files',
        ])
        ->assertDispatched('copy-to-clipboard', text: 'improve the agent context files');
});

test('submitFeedback with nothing to export leaves the page in its editing state', function () {
    Livewire::test('pages::context-page', ['slug' => 'test-project'])
        ->call('submitFeedback')
        ->assertSet('submissionReceipt', null);
});

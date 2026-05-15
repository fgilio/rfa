<?php

use App\Actions\DiscoverAgentContextFilesAction;
use App\Actions\LoadContextCommentsAction;
use App\Actions\ResolveProjectAction;
use App\Events\HardReloadShortcutPressed;
use App\Events\RefreshShortcutPressed;
use App\Models\Comment;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

test('mount writes the project id to the active-project-id cache key', function () {
    Cache::forget('rfa.active-project-id');

    Livewire::test('pages::context-page', ['slug' => 'test-project']);

    expect(Cache::get('rfa.active-project-id'))->toBe($this->project->id);
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

test('startNewFeedback clears the submitted, exportResult, and globalComment fields', fn () => Livewire::test('pages::context-page', ['slug' => 'test-project'])
    ->set('submitted', true)
    ->set('exportResult', '/tmp/repo/.rfa/feedback.md')
    ->set('globalComment', 'leftover thoughts')
    ->call('startNewFeedback')
    ->assertSet('submitted', false)
    ->assertSet('exportResult', null)
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

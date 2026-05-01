<?php

use App\Actions\DiscoverAgentContextFilesAction;
use App\Actions\LoadContextCommentsAction;
use App\Actions\ResolveProjectAction;
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

    app()->bind(DiscoverAgentContextFilesAction::class, fn () => new class
    {
        public function handle(string $repoPath): array
        {
            return [];
        }
    });

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

test('startNewFeedback clears the submitted, exportResult, and globalComment fields', fn () => Livewire::test('pages::context-page', ['slug' => 'test-project'])
    ->set('submitted', true)
    ->set('exportResult', '/tmp/repo/.rfa/feedback.md')
    ->set('globalComment', 'leftover thoughts')
    ->call('startNewFeedback')
    ->assertSet('submitted', false)
    ->assertSet('exportResult', null)
    ->assertSet('globalComment', ''));

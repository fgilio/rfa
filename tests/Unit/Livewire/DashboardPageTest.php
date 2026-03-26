<?php

use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('renders project list', function () {
    Project::create([
        'slug' => 'my-app',
        'name' => 'My App',
        'path' => '/tmp/my-app',
        'git_common_dir' => '/tmp/my-app/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Livewire::test('pages::dashboard-page')
        ->assertSee('My App')
        ->assertSee('/tmp/my-app');
});

test('removeProject removes a project and refreshes the list', function () {
    $project = Project::create([
        'slug' => 'my-app',
        'name' => 'My App',
        'path' => '/tmp/my-app',
        'git_common_dir' => '/tmp/my-app/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Livewire::test('pages::dashboard-page')
        ->assertSee('My App')
        ->call('removeProject', $project->id)
        ->assertDontSee('My App');

    expect(Project::find($project->id))->toBeNull();
});

test('loadProjects changes sort order', function () {
    Project::create([
        'slug' => 'alpha',
        'name' => 'Alpha',
        'path' => '/tmp/alpha',
        'git_common_dir' => '/tmp/alpha/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Project::create([
        'slug' => 'beta',
        'name' => 'Beta',
        'path' => '/tmp/beta',
        'git_common_dir' => '/tmp/beta/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Livewire::test('pages::dashboard-page')
        ->call('loadProjects', 'alpha')
        ->assertSee('Alpha')
        ->assertSee('Beta')
        ->assertSet('sortBy', 'alpha');
});

test('shows comment count when project has review comments', function () {
    $project = Project::create([
        'slug' => 'my-app',
        'name' => 'My App',
        'path' => '/tmp/my-app',
        'git_common_dir' => '/tmp/my-app/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    ReviewSession::create([
        'project_id' => $project->id,
        'repo_path' => '/tmp/my-app',
        'context_fingerprint' => 'abc123',
        'viewed_files' => [],
        'comments' => [
            ['id' => 1, 'body' => 'Fix this'],
            ['id' => 2, 'body' => 'And this'],
        ],
    ]);

    Livewire::test('pages::dashboard-page')
        ->assertSee('2');
});

test('renders search input and keyboard hints', function () {
    Project::create([
        'slug' => 'my-app',
        'name' => 'My App',
        'path' => '/tmp/my-app',
        'git_common_dir' => '/tmp/my-app/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Livewire::test('pages::dashboard-page')
        ->assertSee('Filter projects...')
        ->assertSee('1 project');
});

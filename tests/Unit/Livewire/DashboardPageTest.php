<?php

use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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

// -- registerProject (drag-and-drop) --

test('registerProject registers a git repo and redirects to its review page', function () {
    $repoPath = $this->createTempDirectory('rfa_dash_drop_');
    $this->initTestRepo($repoPath);
    File::put($repoPath.'/file.txt', 'hello');
    $this->commitTestRepo($repoPath, 'init');

    $component = Livewire::test('pages::dashboard-page')
        ->call('registerProject', $repoPath);

    $project = Project::where('path', realpath($repoPath))->first();

    expect($project)->not->toBeNull();

    $component->assertRedirect("/p/{$project->slug}");
});

test('registerProject rejects non-git directory without redirect', function () {
    $nonGitPath = $this->createTempDirectory('rfa_dash_nongit_');

    Livewire::test('pages::dashboard-page')
        ->call('registerProject', $nonGitPath)
        ->assertNoRedirect();

    expect(Project::count())->toBe(0);
});

test('registerProject rejects nonexistent path without redirect', function () {
    Livewire::test('pages::dashboard-page')
        ->call('registerProject', '/tmp/rfa_nonexistent_'.uniqid())
        ->assertNoRedirect();

    expect(Project::count())->toBe(0);
});

test('registerProject returns existing project when re-dropped', function () {
    $repoPath = $this->createTempDirectory('rfa_dash_redrop_');
    $this->initTestRepo($repoPath);
    File::put($repoPath.'/file.txt', 'hello');
    $this->commitTestRepo($repoPath, 'init');

    // First drop
    Livewire::test('pages::dashboard-page')
        ->call('registerProject', $repoPath);

    expect(Project::count())->toBe(1);

    // Second drop of same path
    $component = Livewire::test('pages::dashboard-page')
        ->call('registerProject', $repoPath);

    expect(Project::count())->toBe(1);

    $project = Project::where('path', realpath($repoPath))->first();
    $component->assertRedirect("/p/{$project->slug}");
});

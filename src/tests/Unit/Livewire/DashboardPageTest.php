<?php

use App\Models\Project;
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

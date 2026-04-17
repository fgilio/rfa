<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(fn () => Livewire::withoutLazyLoading());

test('mount loads the project list', function () {
    Project::create([
        'slug' => 'current',
        'name' => 'Current',
        'path' => '/tmp/current',
        'git_common_dir' => '/tmp/current/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Project::create([
        'slug' => 'other',
        'name' => 'Other',
        'path' => '/tmp/other',
        'git_common_dir' => '/tmp/other/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current'])
        ->assertSet('totalProjects', 2)
        ->assertSee('Current')
        ->assertSee('Other');
});

test('search filters the project list', function () {
    Project::create([
        'slug' => 'zyxwvu-included',
        'name' => 'zyxwvu-included',
        'path' => '/tmp/zyxwvu',
        'git_common_dir' => '/tmp/zyxwvu/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Project::create([
        'slug' => 'qponml-excluded',
        'name' => 'qponml-excluded',
        'path' => '/tmp/qponml',
        'git_common_dir' => '/tmp/qponml/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Livewire::test('project-picker', ['currentSlug' => 'anchor', 'projectName' => 'Anchor'])
        ->set('search', 'zyxwvu')
        ->assertSet('totalProjects', 2)
        ->assertSee('zyxwvu-included')
        ->assertDontSee('qponml-excluded');
});

test('selectProject redirects to review-page for a different project', function () {
    Project::create([
        'slug' => 'current',
        'name' => 'Current',
        'path' => '/tmp/current',
        'git_common_dir' => '/tmp/current/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Project::create([
        'slug' => 'other',
        'name' => 'Other',
        'path' => '/tmp/other',
        'git_common_dir' => '/tmp/other/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current'])
        ->call('selectProject', 'other')
        ->assertRedirect(route('review-page', ['slug' => 'other']));
});

test('selectProject on current project dispatches close instead of redirecting', function () {
    Project::create([
        'slug' => 'current',
        'name' => 'Current',
        'path' => '/tmp/current',
        'git_common_dir' => '/tmp/current/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current'])
        ->call('selectProject', 'current')
        ->assertNoRedirect()
        ->assertDispatched('project-picker:close');
});

test('removeProject redirects to next target when removing the current project', function () {
    $current = Project::factory()->create(['slug' => 'current', 'updated_at' => now()->subHour()]);
    Project::factory()->create(['slug' => 'surviving', 'updated_at' => now()]);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('current');

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current'])
        ->call('removeProject', $current->id)
        ->assertRedirect(route('review-page', ['slug' => 'surviving']));

    expect(Project::find($current->id))->toBeNull();
});

test('removeProject redirects to no-projects when removing the last project', function () {
    $only = Project::factory()->create(['slug' => 'only']);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('only');

    Livewire::test('project-picker', ['currentSlug' => 'only', 'projectName' => 'Only'])
        ->call('removeProject', $only->id)
        ->assertRedirect(route('no-projects'));

    expect(Project::find($only->id))->toBeNull();
});

test('removeProject refreshes list when removing a non-current project', function () {
    $keep = Project::factory()->create(['slug' => 'keep']);
    $gone = Project::factory()->create(['slug' => 'gone']);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('keep');

    Livewire::test('project-picker', ['currentSlug' => 'keep', 'projectName' => 'Keep'])
        ->assertSet('totalProjects', 2)
        ->call('removeProject', $gone->id)
        ->assertNoRedirect()
        ->assertSet('totalProjects', 1);
});

test('projects-changed refreshes the list', function () {
    Project::factory()->create(['slug' => 'a']);

    $component = Livewire::test('project-picker', ['currentSlug' => 'a', 'projectName' => 'A'])
        ->assertSet('totalProjects', 1);

    Project::factory()->create(['slug' => 'b']);

    $component->dispatch('projects-changed')
        ->assertSet('totalProjects', 2);
});

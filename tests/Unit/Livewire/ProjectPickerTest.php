<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Comment;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(fn () => Livewire::withoutLazyLoading());

test('mount loads the project list', function () {
    Project::factory()->create(['slug' => 'current', 'name' => 'Current']);
    Project::factory()->create(['slug' => 'other', 'name' => 'Other']);

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current'])
        ->assertSet('totalProjects', 2)
        ->assertSee('Current')
        ->assertSee('Other');
});

test('search filters the project list', function () {
    Project::factory()->create(['slug' => 'zyxwvu-included', 'name' => 'zyxwvu-included']);
    Project::factory()->create(['slug' => 'qponml-excluded', 'name' => 'qponml-excluded']);

    Livewire::test('project-picker', ['currentSlug' => 'anchor', 'projectName' => 'Anchor'])
        ->set('search', 'zyxwvu')
        ->assertSet('totalProjects', 2)
        ->assertSee('zyxwvu-included')
        ->assertDontSee('qponml-excluded');
});

test('selectProject redirects to review-page for a different project', function () {
    Project::factory()->create(['slug' => 'current']);
    Project::factory()->create(['slug' => 'other']);

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current'])
        ->call('selectProject', 'other')
        ->assertRedirect(route('review-page', ['slug' => 'other']));
});

test('selectProject preserves the context mode when redirecting', function () {
    Project::factory()->create(['slug' => 'current']);
    Project::factory()->create(['slug' => 'other']);

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current', 'mode' => 'context'])
        ->call('selectProject', 'other')
        ->assertRedirect(route('context-page', ['slug' => 'other']));
});

test('selectProject defaults to review-page when mode is unrecognized', function () {
    Project::factory()->create(['slug' => 'current']);
    Project::factory()->create(['slug' => 'other']);

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current', 'mode' => 'whatever'])
        ->call('selectProject', 'other')
        ->assertRedirect(route('review-page', ['slug' => 'other']));
});

test('removeProject redirects to select-repo when removing the current project', function () {
    $current = Project::factory()->create(['slug' => 'current', 'updated_at' => now()->subHour()]);
    Project::factory()->create(['slug' => 'surviving', 'updated_at' => now()]);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('current');

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current'])
        ->call('removeProject', $current->id)
        ->assertRedirect(route('select-repo'));

    expect(Project::find($current->id))->toBeNull();
});

test('removeProject redirects to select-repo when removing the last project', function () {
    $only = Project::factory()->create(['slug' => 'only']);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('only');

    Livewire::test('project-picker', ['currentSlug' => 'only', 'projectName' => 'Only'])
        ->call('removeProject', $only->id)
        ->assertRedirect(route('select-repo'));

    expect(Project::find($only->id))->toBeNull();
});

test('removeProject refreshes list when removing a non-current project', function () {
    Project::factory()->create(['slug' => 'keep']);
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

test('trigger renders Switch repo tooltip and aria-label', function () {
    Project::factory()->create(['slug' => 'only']);

    Livewire::test('project-picker', ['currentSlug' => 'only', 'projectName' => 'Only'])
        ->assertSee('Switch repo')
        ->assertSee('Switch to repo...');
});

test('trash button announces repo name via aria-label', function () {
    Project::factory()->create(['slug' => 'anchor', 'name' => 'anchor']);
    Project::factory()->create(['slug' => 'removable-repo', 'name' => 'removable-repo']);

    Livewire::test('project-picker', ['currentSlug' => 'anchor', 'projectName' => 'anchor'])
        ->assertSee('Remove removable-repo')
        ->assertSee('Remove repo');
});

test('confirm copy warns about data loss when removing the current repo', function () {
    Project::factory()->create(['slug' => 'current-repo', 'name' => 'current-repo']);

    Livewire::test('project-picker', ['currentSlug' => 'current-repo', 'projectName' => 'current-repo'])
        ->assertSee('and all its review data')
        ->assertSee('return to the repo picker');
});

test('confirm copy is minimal when removing a non-current repo', function () {
    Project::factory()->create(['slug' => 'sibling-repo', 'name' => 'sibling-repo']);

    Livewire::test('project-picker', ['currentSlug' => 'anchor', 'projectName' => 'anchor'])
        ->assertSee('from the list?')
        ->assertDontSee('review data');
});

test('confirm copy mentions comment count when repo has unsubmitted comments', function () {
    $repo = Project::factory()->create(['slug' => 'with-comments', 'name' => 'with-comments']);

    Comment::factory()->count(3)->for($repo, 'project')->create(['repo_path' => $repo->path]);

    Livewire::test('project-picker', ['currentSlug' => 'anchor', 'projectName' => 'Anchor'])
        ->assertSee('3 comments will be deleted');
});

test('confirm copy uses singular comment form for a single comment', function () {
    $repo = Project::factory()->create(['slug' => 'one-comment', 'name' => 'one-comment']);

    Comment::factory()->for($repo, 'project')->create(['repo_path' => $repo->path]);

    Livewire::test('project-picker', ['currentSlug' => 'anchor', 'projectName' => 'Anchor'])
        ->assertSee('1 comment will be deleted')
        ->assertDontSee('1 comments will be deleted');
});

test('confirm copy omits comment clause when there are no unsubmitted comments', function () {
    Project::factory()->create(['slug' => 'clean-repo', 'name' => 'clean-repo']);

    Livewire::test('project-picker', ['currentSlug' => 'anchor', 'projectName' => 'Anchor'])
        ->assertDontSee('will be deleted');
});

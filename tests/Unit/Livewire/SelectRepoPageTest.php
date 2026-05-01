<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Comment;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('renders the empty state when no repos exist', function () {
    Livewire::test('pages::select-repo-page')
        ->assertSee('Be in the loop.')
        ->assertStatus(200);
});

test('select-repo route serves the empty state when no repos exist', function () {
    $this->get(route('select-repo'))->assertSee('Be in the loop.');
});

test('root route redirects to select-repo when no repos exist', function () {
    $this->get('/')->assertRedirect(route('select-repo'));
});

test('root route redirects to last-opened review page when cached slug is valid', function () {
    Project::factory()->create(['slug' => 'resumed']);
    app(ResolveStartupRouteAction::class)->rememberLastOpened('resumed');

    $this->get('/')->assertRedirect(route('review-page', ['slug' => 'resumed']));
});

test('root route redirects to select-repo when repos exist but no cached slug', function () {
    Project::factory()->create(['slug' => 'only-one']);

    $this->get('/')->assertRedirect(route('select-repo'));
});

test('renders the repo list when repos exist', function () {
    Project::factory()->create(['slug' => 'alpha', 'name' => 'Alpha']);
    Project::factory()->create(['slug' => 'beta', 'name' => 'Beta']);

    Livewire::test('pages::select-repo-page')
        ->assertSet('totalProjects', 2)
        ->assertSee('Pick a repo')
        ->assertSee('Alpha')
        ->assertSee('Beta');
});

test('selectProject redirects to the chosen repo review page', function () {
    Project::factory()->create(['slug' => 'chosen']);

    Livewire::test('pages::select-repo-page')
        ->call('selectProject', 'chosen')
        ->assertRedirect(route('review-page', ['slug' => 'chosen']));
});

test('removeProject on a non-current repo refreshes the list in place', function () {
    Project::factory()->create(['slug' => 'keep']);
    $gone = Project::factory()->create(['slug' => 'gone']);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('keep');

    Livewire::test('pages::select-repo-page')
        ->assertSet('totalProjects', 2)
        ->call('removeProject', $gone->id)
        ->assertNoRedirect()
        ->assertSet('totalProjects', 1);
});

test('projects-changed refreshes the list', function () {
    Project::factory()->create(['slug' => 'a']);

    $component = Livewire::test('pages::select-repo-page')
        ->assertSet('totalProjects', 1);

    Project::factory()->create(['slug' => 'b']);

    $component->dispatch('projects-changed')
        ->assertSet('totalProjects', 2);
});

test('search filters the repo list', function () {
    Project::factory()->create(['slug' => 'alpha-match', 'name' => 'alpha-match']);
    Project::factory()->create(['slug' => 'beta-skip', 'name' => 'beta-skip']);

    Livewire::test('pages::select-repo-page')
        ->set('search', 'alpha')
        ->assertSee('alpha-match')
        ->assertDontSee('beta-skip');
});

test('changing sortBy re-queries and refreshes the list', function () {
    Project::factory()->create(['slug' => 'a']);

    $component = Livewire::test('pages::select-repo-page')
        ->assertSet('totalProjects', 1);

    Project::factory()->create(['slug' => 'b']);

    $component->set('sortBy', 'alpha')
        ->assertSet('totalProjects', 2);
});

test('direct visit to /select-repo renders list even when last-opened cache is valid', function () {
    Project::factory()->create(['slug' => 'cached', 'name' => 'cached']);
    app(ResolveStartupRouteAction::class)->rememberLastOpened('cached');

    $this->get(route('select-repo'))
        ->assertOk()
        ->assertSee('Pick a repo')
        ->assertSee('cached');
});

test('trash button announces repo name via aria-label on the page', function () {
    Project::factory()->create(['slug' => 'another-repo', 'name' => 'another-repo']);

    Livewire::test('pages::select-repo-page')
        ->assertSee('Remove another-repo')
        ->assertSee('Remove repo');
});

test('page confirm copy warns about data loss on the cached current repo', function () {
    Project::factory()->create(['slug' => 'last-opened-repo', 'name' => 'last-opened-repo']);
    app(ResolveStartupRouteAction::class)->rememberLastOpened('last-opened-repo');

    Livewire::test('pages::select-repo-page')
        ->assertSee('and all its review data')
        ->assertSee('return to the repo picker');
});

test('page confirm copy is minimal for repos that are not the cached current', function () {
    Project::factory()->create(['slug' => 'sibling', 'name' => 'sibling']);

    Livewire::test('pages::select-repo-page')
        ->assertSee('from the list?')
        ->assertDontSee('review data');
});

test('page confirm copy pluralizes the comment clause correctly', function () {
    $repo = Project::factory()->create(['slug' => 'two-comments', 'name' => 'two-comments']);

    Comment::factory()->count(2)->for($repo, 'project')->create(['repo_path' => $repo->path]);

    Livewire::test('pages::select-repo-page')
        ->assertSee('2 comments will be deleted');
});

test('removing the cached current repo from the page redirects to select-repo', function () {
    $current = Project::factory()->create(['slug' => 'current-on-page', 'name' => 'current-on-page']);
    Project::factory()->create(['slug' => 'other-on-page']);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('current-on-page');

    Livewire::test('pages::select-repo-page')
        ->call('removeProject', $current->id)
        ->assertRedirect(route('select-repo'));

    expect(Project::find($current->id))->toBeNull();
});

test('footer reflects total and filtered counts', function () {
    Project::factory()->create(['slug' => 'match-me', 'name' => 'match-me']);
    Project::factory()->create(['slug' => 'skip-me', 'name' => 'skip-me']);

    Livewire::test('pages::select-repo-page')
        ->assertSee('2 repos')
        ->set('search', 'match')
        ->assertSee('1/2 repos');
});

test('mount clears the cached active-project-id so cmd-shift-k opens the file picker', function () {
    Cache::put('rfa.active-project-id', 42);

    Livewire::test('pages::select-repo-page');

    expect(Cache::get('rfa.active-project-id'))->toBeNull();
});

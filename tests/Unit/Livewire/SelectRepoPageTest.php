<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('renders the empty state when no repos exist', function () {
    Livewire::test('pages::select-repo-page')
        ->assertSee('No repos yet')
        ->assertStatus(200);
});

test('select-repo route serves the empty state when no repos exist', function () {
    $this->get(route('select-repo'))->assertSee('No repos yet');
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

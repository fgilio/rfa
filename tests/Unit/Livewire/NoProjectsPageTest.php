<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('renders the no-projects empty state', function () {
    Livewire::test('pages::no-projects-page')
        ->assertSee('No projects yet')
        ->assertStatus(200);
});

test('no-projects route serves the empty state page', function () {
    $this->get('/no-projects')->assertSee('No projects yet');
});

test('root route redirects to no-projects when no projects exist', function () {
    $this->get('/')->assertRedirect(route('no-projects'));
});

test('root route redirects to most-recent review page when a project exists', function () {
    Project::factory()->create(['slug' => 'only-one']);

    $this->get('/')->assertRedirect(route('review-page', ['slug' => 'only-one']));
});

test('redirects to review page when a project is registered via projects-changed', function () {
    $component = Livewire::test('pages::no-projects-page')
        ->assertSee('No projects yet');

    Project::factory()->create(['slug' => 'just-added']);

    $component->dispatch('projects-changed')
        ->assertRedirect(route('review-page', ['slug' => 'just-added']));
});

test('stays on page when projects-changed fires but no project exists', function () {
    Livewire::test('pages::no-projects-page')
        ->dispatch('projects-changed')
        ->assertNoRedirect();
});

<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('deleting the current repo from the picker lands on select-repo and lists remaining repos', function () {
    $current = Project::factory()->create(['slug' => 'current', 'name' => 'Current repo']);
    Project::factory()->create(['slug' => 'surviving', 'name' => 'Surviving repo']);

    app(ResolveStartupRouteAction::class)->rememberLastOpened('current');

    Livewire::withoutLazyLoading();

    Livewire::test('project-picker', ['currentSlug' => 'current', 'projectName' => 'Current'])
        ->call('removeProject', $current->id)
        ->assertRedirect(route('select-repo'));

    expect(Project::find($current->id))->toBeNull();
    expect(app(ResolveStartupRouteAction::class)->lastOpenedSlug())->toBeNull();

    $this->get(route('select-repo'))
        ->assertSee('Pick a repo')
        ->assertSee('Surviving repo')
        ->assertDontSee('Current repo');
});

test('deleting the last repo lands on select-repo empty state', function () {
    $only = Project::factory()->create(['slug' => 'only']);
    app(ResolveStartupRouteAction::class)->rememberLastOpened('only');

    Livewire::withoutLazyLoading();

    Livewire::test('project-picker', ['currentSlug' => 'only', 'projectName' => 'Only'])
        ->call('removeProject', $only->id)
        ->assertRedirect(route('select-repo'));

    $this->get(route('select-repo'))->assertSee('No repos yet');
});

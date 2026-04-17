<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('renders the no-projects empty state', function () {
    Livewire::test('pages::no-projects-page')
        ->assertSee('No projects yet')
        ->assertStatus(200);
});

test('root route resolves to the no-projects page', function () {
    $this->get('/')->assertSee('No projects yet');
});

<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

test('inline comments persist after page reload', function () {
    $page = $this->visit('/');

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Persistent comment');
    $page->press('Save');
    $page->assertSee('Persistent comment');

    $page->refresh();
    $page->assertSee('Persistent comment');
});

test('global comment persists after page reload', function () {
    $page = $this->visit('/');

    // Set global comment directly via Livewire JS API (bypasses wire:model.blur timing issues)
    $page->script("
        const wireId = document.querySelector('[data-testid=\"review-component\"]').getAttribute('wire:id');
        Livewire.find(wireId).set('globalComment', 'Global persisted note');
    ");
    // Wait for Livewire to process the server round-trip
    $page->page()->waitForFunction("document.querySelector('[data-testid=\"review-component\"] textarea')?.value === 'Global persisted note'");

    $page->refresh();

    $value = $page->page()->getByPlaceholder('Overall review comment', false)->inputValue();
    expect($value)->toBe('Global persisted note');
});

test('viewed files persist after page reload', function () {
    $page = $this->visit('/');

    $page->page()->getByLabel('Viewed')->first()->click();
    // Wait for Livewire round-trip to complete before refreshing
    $page->assertSee('1/3 viewed');

    $page->refresh();
    $page->assertSee('1/3 viewed');
});

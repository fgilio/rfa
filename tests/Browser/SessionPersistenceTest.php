<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('inline comments persist after page reload', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Persistent comment');
    $page->press('Save');
    $page->assertSee('Persistent comment');

    $page->refresh();
    $page->assertSee('Persistent comment');
});

test('global comment persists after page reload', function () {
    $page = $this->visit($this->projectUrl());

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
})->repeat(100);

test('reviewed files persist after page reload', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->first()->click();
    // Alpine updates the counter on the next microtask; poll until it renders.
    $page->page()->waitForFunction("document.querySelector('[data-testid=\"reviewed-counter\"]')?.textContent?.includes('1/3 reviewed')");
    // Counter above is driven by Alpine's local mirror, which fires before the
    // toggleReviewed Livewire POST reaches the DB. Refreshing mid-POST aborts
    // the request (net::ERR_ABORTED) and the toggle is lost on reload — flake
    // amplified under --parallel. Wait for network idle to drain the round-trip.
    $page->waitForEvent('networkidle');

    $page->refresh();
    $page->page()->waitForFunction("document.querySelector('[data-testid=\"reviewed-counter\"]')?.textContent?.includes('1/3 reviewed')");
});

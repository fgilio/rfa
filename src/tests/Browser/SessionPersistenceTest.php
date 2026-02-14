<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

const CLICK_LINE_SESSION = "document.querySelectorAll('td.diff-line-num')[0].click()";

test('inline comments persist after page reload', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE_SESSION);
    $page->type('[placeholder*="Write a comment"]', 'Persistent comment');
    $page->press('Save');
    $page->assertSee('Persistent comment');

    $page->refresh();
    $page->assertSee('Persistent comment');
});

test('global comment persists after page reload', function () {
    $page = $this->visit('/');

    // Set global comment directly via Livewire JS API (bypasses wire:model.blur timing issues)
    $page->script("
        const wireId = document.querySelector('[wire\\\\:id]').getAttribute('wire:id');
        Livewire.find(wireId).set('globalComment', 'Global persisted note');
    ");
    // Wait for Livewire to process the server round-trip
    $page->script("new Promise(resolve => {
        const check = () => document.querySelector('[placeholder*=\"Overall review comment\"]').value === 'Global persisted note'
            ? resolve() : setTimeout(check, 50);
        check();
    })");

    $page->refresh();

    $value = $page->script("document.querySelector('[placeholder*=\"Overall review comment\"]').value");
    expect($value)->toBe('Global persisted note');
});

test('viewed files persist after page reload', function () {
    $page = $this->visit('/');

    // Click the first Flux ui-checkbox element (Viewed checkbox)
    $page->script("document.querySelector('ui-checkbox').click()");
    // Wait for Livewire round-trip to complete before refreshing
    $page->assertSee('1/3 viewed');

    $page->refresh();
    $page->assertSee('1/3 viewed');
});

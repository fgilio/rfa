<?php

beforeEach(function () {
    $this->setUpMultiHunkTestRepo();
});

test('clicking expand hidden lines shows full file context', function () {
    $page = $this->visit($this->projectUrl());

    // Wait for diff to load
    $page->assertSee('changed1');

    // Should see the expand button for the gap between hunks
    $page->page()->locator('button')->filter(['hasText' => 'hidden lines'])->first()->click();

    // After expansion, previously hidden lines should be visible
    $page->assertSee('line15');

    // No more "hidden lines" buttons after gap is filled
    expect($page->page()->locator('button')->filter(['hasText' => 'hidden lines'])->count())->toBe(0);
});

test('show full file button reveals all hidden lines', function () {
    $page = $this->visit($this->projectUrl());

    // Wait for diff to load
    $page->assertSee('changed1');

    $page->page()->getByRole('button', ['name' => 'Show full file'])->click();

    // After expansion, previously hidden content is visible
    $page->assertSee('line15');

    // No more expand buttons
    expect($page->page()->locator('button')->filter(['hasText' => 'hidden lines'])->count())->toBe(0);
    expect($page->page()->getByRole('button', ['name' => 'Show full file'])->count())->toBe(0);
});

test('keyboard-expanding a gap returns focus to the remaining expander', function () {
    $page = $this->visit($this->projectUrl());

    // Multi-hunk fixture: a single 22-line gap between the hunks, keyed at hunk
    // index 1. With >15 hidden it renders the "15" tier chip + an "all" button.
    $page->assertSee('changed1');
    $page->page()->locator('[data-expand-gap="1"]')->first()->waitFor();

    // Keyboard-activate the first gap expander (Enter → click with detail 0).
    $page->page()->locator('[data-expand-gap="1"]')->first()->press('Enter');

    // Partial expand from the top of the gap: 22 − 15 = 7 lines remain, so a
    // smaller expander stays put at the same gap.
    $page->assertSee('7 hidden lines');

    // The chip we activated was destroyed by the re-render, so focus landing
    // back on a gap-1 expander (instead of falling to <body>) proves restoration.
    // Wait on the :focus selector so it auto-retries — the Livewire morph plus
    // the post-render refocus tick can lag a slow, parallel CI runner.
    $page->page()->locator('[data-expand-gap="1"]:focus')->waitFor();
});

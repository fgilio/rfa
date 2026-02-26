<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpMultiHunkTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
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

    $page->page()->locator('button')->filter(['hasText' => 'Show full file'])->first()->click();

    // After expansion, previously hidden content is visible
    $page->assertSee('line15');

    // No more expand buttons
    expect($page->page()->locator('button')->filter(['hasText' => 'hidden lines'])->count())->toBe(0);
    expect($page->page()->locator('button')->filter(['hasText' => 'Show full file'])->count())->toBe(0);
});

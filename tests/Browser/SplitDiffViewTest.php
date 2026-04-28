<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('defaults to unified view with split toggle visible', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-table-unified')->first()->waitFor();

    expect($page->page()->getByTestId('diff-table-unified')->count())->toBeGreaterThan(0);
    expect($page->page()->getByTestId('diff-table-split')->count())->toBe(0);
    expect($page->page()->getByLabel('Switch to split view')->isVisible())->toBeTrue();
});

test('clicking the toggle switches into split layout', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByTestId('diff-table-unified')->first()->waitFor();

    $page->page()->getByLabel('Switch to split view')->click();

    $page->page()->getByTestId('diff-table-split')->first()->waitFor();
    expect($page->page()->getByTestId('diff-table-split')->count())->toBeGreaterThan(0);
    $page->page()->getByTestId('diff-table-unified')->first()->waitFor(['state' => 'detached']);
    expect($page->page()->getByLabel('Switch to unified view')->isVisible())->toBeTrue();
});

test('toggle returns to unified view', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->getByTestId('diff-table-split')->first()->waitFor();

    $page->page()->getByLabel('Switch to unified view')->click();

    $page->page()->getByTestId('diff-table-unified')->first()->waitFor();
    $page->page()->getByTestId('diff-table-split')->first()->waitFor(['state' => 'detached']);
});

test('view mode persists in localStorage across page reload', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->getByTestId('diff-table-split')->first()->waitFor();

    $stored = $page->page()->evaluate("localStorage.getItem('rfa.diffViewMode')");
    expect(json_decode($stored, true))->toBe('split');

    $page->refresh();

    $page->page()->getByTestId('diff-table-split')->first()->waitFor();
    expect($page->page()->getByTestId('diff-table-split')->count())->toBeGreaterThan(0);
});

test('split view shows old and new content lines simultaneously', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->getByTestId('diff-table-split')->first()->waitFor();

    $page->assertSee('function greet($name) {')
        ->assertSee('function greet(string $name): string {');
});

test('clicking a line number in split opens a comment form', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->getByTestId('diff-table-split')->first()->waitFor();

    $page->page()->locator('[data-testid="diff-table-split"] [data-testid="diff-line-number"]')->first()->click();

    $page->assertSee('Cancel');
});

test('saving comment from split view shows it inline', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();

    $lineNum = $page->page()->locator('[data-testid="diff-table-split"] [data-testid="diff-line-number"]')->first();
    $lineNum->waitFor();
    $lineNum->click();

    $page->page()->getByPlaceholder('Write a comment', false)->fill('split-view comment');
    $page->press('Save');

    $page->assertSee('split-view comment');
});

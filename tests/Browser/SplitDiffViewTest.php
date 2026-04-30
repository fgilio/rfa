<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('defaults to unified view with split toggle visible', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->locator('[data-testid="diff-table"][data-view-mode="unified"]')->first()->waitFor();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor(['state' => 'hidden']);
    $page->page()->getByLabel('Switch to split view')->waitFor();
});

test('clicking the toggle switches into split layout', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByTestId('diff-table')->first()->waitFor();

    $page->page()->getByLabel('Switch to split view')->click();

    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="unified"]')->first()->waitFor(['state' => 'hidden']);
    $page->page()->getByLabel('Switch to unified view')->waitFor();
});

test('toggle returns to unified view', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();

    $page->page()->getByLabel('Switch to unified view')->click();

    $page->page()->locator('[data-testid="diff-table"][data-view-mode="unified"]')->first()->waitFor();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor(['state' => 'hidden']);
});

test('view mode persists in localStorage across page reload', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();

    $stored = $page->page()->evaluate("localStorage.getItem('rfa.diffViewMode')");
    expect(json_decode($stored, true))->toBe('split');

    $page->refresh();
    // refresh() doesn't run visitAndLoad's networkidle wait, so the lazy
    // diff-file children may not have rendered yet under parallel pressure.
    $page->page()->waitForLoadState('networkidle');

    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();
});

test('split view shows old and new content lines simultaneously', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();

    $page->assertSee('function greet($name) {')
        ->assertSee('function greet(string $name): string {');
});

test('clicking a line number in split opens a comment form', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();

    // Target the new-side gutter on a row that has a new line number — split
    // CSS hides .diff-cell-num-new on remove rows, so first() with the
    // unscoped selector could pick a hidden cell.
    $page->page()->locator('[data-view-mode="split"] .diff-line[data-line-new] .diff-cell-num-new')->first()->click();

    $page->assertSee('Cancel');
});

test('saving comment from split view shows it inline', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();

    $lineNum = $page->page()->locator('[data-view-mode="split"] .diff-line[data-line-new] .diff-cell-num-new')->first();
    $lineNum->waitFor();
    $lineNum->click();

    $page->page()->getByPlaceholder('Write a comment', false)->fill('split-view comment');
    $page->press('Save');

    $page->assertSee('split-view comment');
});

test('split view pairs a remove with its add on the same row', function () {
    // Regression: gutter cells used to consume the empty side of a row in
    // split mode, blocking grid-auto-flow:dense from packing rem+add together.
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();

    $rect = $page->page()->evaluate(<<<'JS'
        () => {
            const table = document.querySelector(
                '[data-testid="diff-table"][data-view-mode="split"]:has(.diff-line[data-type="remove"]):has(.diff-line[data-type="add"])'
            );
            const rr = table.querySelector('.diff-line[data-type="remove"]').getBoundingClientRect();
            const ar = table.querySelector('.diff-line[data-type="add"]').getBoundingClientRect();
            return { rem: { x: rr.x, y: rr.y }, add: { x: ar.x, y: ar.y } };
        }
    JS);

    expect(abs($rect['rem']['y'] - $rect['add']['y']))->toBeLessThan(2.0)
        ->and($rect['rem']['x'])->toBeLessThan($rect['add']['x']);
});

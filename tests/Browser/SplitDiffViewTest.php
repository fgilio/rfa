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

test('clicking the new side of a shifted context line anchors the form to that row', function () {
    $this->setUpShiftedContextTestRepo();

    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();

    $shiftedFile = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("shifted.txt"))');
    $shiftedContextRow = $shiftedFile->locator('.diff-line[data-type="context"][data-line-old="5"][data-line-new="4"]');
    $shiftedContextRow->waitFor();

    $shiftedContextRow->locator('.diff-cell-num-new')->click();

    expect($shiftedFile->getByPlaceholder('Write a comment', false)->count())->toBe(1);
    // Selection (and therefore the form) is anchored to the clicked row itself.
    $shiftedFile->locator('.diff-line.line-selected[data-line-old="5"][data-line-new="4"]')->waitFor();
    expect($shiftedFile->locator('.diff-line.line-selected')->count())->toBe(1);
});

test('clicking the old side of a shifted context line anchors the form to that row, not its neighbour', function () {
    // Old line 5 is new line 4 after the deletion. The pre-fix code anchored by
    // new-number only, so clicking the old-side gutter landed the form on the
    // *next* row (old 6 / new 5) instead of the row that was clicked.
    $this->setUpShiftedContextTestRepo();

    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();

    $shiftedFile = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("shifted.txt"))');
    $shiftedContextRow = $shiftedFile->locator('.diff-line[data-type="context"][data-line-old="5"][data-line-new="4"]');
    $shiftedContextRow->waitFor();

    $shiftedContextRow->locator('.diff-cell-num-old')->click();

    expect($shiftedFile->getByPlaceholder('Write a comment', false)->count())->toBe(1);
    $shiftedFile->locator('.diff-line.line-selected[data-line-old="5"][data-line-new="4"]')->waitFor();
    expect($shiftedFile->locator('.diff-line.line-selected')->count())->toBe(1);
});

test('clicking a removed line whose old number collides with a shifted new number opens exactly one form', function () {
    // Old line 4 was deleted; the shifted context row carries new line 4. The
    // pre-fix code matched both rows by bare line number and opened two forms.
    $this->setUpShiftedContextTestRepo();

    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();

    $shiftedFile = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("shifted.txt"))');
    $removedRow = $shiftedFile->locator('.diff-line[data-type="remove"][data-line-old="4"]');
    $removedRow->waitFor();

    $removedRow->locator('.diff-cell-num-old')->click();

    expect($shiftedFile->getByPlaceholder('Write a comment', false)->count())->toBe(1);
});

test('split view pairs a remove with its add on the same row', function () {
    // Regression: gutter cells used to consume the empty side of a row in
    // split mode, blocking grid-auto-flow:dense from packing rem+add together.
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    // Wait for the split table that already has *both* a remove and an add line,
    // not just the table shell: the diff lines arrive via a lazy x-intersect
    // round-trip and can lag under load, and the evaluate() below assumes this
    // compound table exists (otherwise querySelector returns null and throws).
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]:has(.diff-line[data-type="remove"]):has(.diff-line[data-type="add"])')->first()->waitFor();

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

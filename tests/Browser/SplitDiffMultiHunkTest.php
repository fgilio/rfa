<?php

beforeEach(function () {
    $this->setUpAsymmetricMultiHunkTestRepo();
});

test('split view does not pair removes and adds across hunk boundaries', function () {
    // Regression: with `grid-auto-flow: row dense` on the outer .diff-grid,
    // an unpaired add in hunk 1 (cols 3-4) left an empty cols 1-2 slot that
    // an unpaired remove from hunk 2 would back-fill into, producing a row
    // with content from two unrelated hunks. The per-hunk subgrid wrapper
    // contains dense flow within each hunk.
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();

    $rect = $page->page()->evaluate(<<<'JS'
        () => {
            const table = document.querySelector(
                '[data-testid="diff-table"][data-view-mode="split"]:has(.diff-line[data-type="add"]):has(.diff-line[data-type="remove"])'
            );
            const ar = table.querySelector('.diff-line[data-type="add"]').getBoundingClientRect();
            const rr = table.querySelector('.diff-line[data-type="remove"]').getBoundingClientRect();
            return { add: { y: ar.y, h: ar.height }, rem: { y: rr.y, h: rr.height } };
        }
    JS);

    // Add (hunk 1) and remove (hunk 2) live in different hunks separated by
    // many context lines, so their rows must be far apart vertically — at
    // least several line-heights. If dense flow leaked, they'd share a Y.
    expect(abs($rect['add']['y'] - $rect['rem']['y']))->toBeGreaterThan($rect['add']['h'] * 3);
});

test('split view context lines render at one line-height', function () {
    // Regression: Phiki emits a trailing "\n" token on every line. With
    // white-space: pre-wrap on the cell, that newline rendered as a visible
    // second line, doubling each context row. At the diff code size
    // (.diff-cell: 16px text, 1.667 line-height) one row is ~26.7px, so a
    // doubled row would be ~53px — the bounds below bracket the single-line
    // height and stay well under the doubled threshold.
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Switch to split view')->click();
    $page->page()->locator('[data-testid="diff-table"][data-view-mode="split"]')->first()->waitFor();

    $heights = $page->page()->evaluate(<<<'JS'
        () => Array.from(
            document.querySelectorAll('[data-testid="diff-table"][data-view-mode="split"] .diff-line[data-type="context"]')
        ).slice(0, 5).map(el => el.getBoundingClientRect().height)
    JS);

    // Guard against the selector silently returning [] and the foreach
    // passing vacuously — would mask a real regression.
    expect($heights)->toHaveCount(5);

    foreach ($heights as $h) {
        expect($h)->toBeGreaterThan(20.0)->toBeLessThan(40.0);
    }
});

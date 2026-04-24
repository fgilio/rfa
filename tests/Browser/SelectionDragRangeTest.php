<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepo();
});

test('press-and-hold on a checkbox, drag across rows, release: range from anchor is selected', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    // Press on the newest commit's checkbox (idx 0), drag to the oldest row (idx 2),
    // release. Dispatch synthetic events so the test doesn't depend on pointer
    // geometry or the overlay popover's viewport layout.
    $page->script(<<<'JS'
        (() => {
            const anchor = document.querySelector('[data-commit-idx="0"]');
            const target = document.querySelector('[data-commit-idx="2"]');
            const checkbox = anchor.querySelector('[data-testid="commit-select-toggle"]');

            checkbox.dispatchEvent(new MouseEvent('mousedown', { button: 0, bubbles: true }));
            target.dispatchEvent(new PointerEvent('pointerover', { bubbles: true, buttons: 1 }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
        })()
    JS);

    $page->page()->getByText('3 selected')->waitFor();
});

test('dragging back toward the anchor shrinks the range', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    $page->script(<<<'JS'
        (() => {
            const anchor = document.querySelector('[data-commit-idx="0"]');
            const far = document.querySelector('[data-commit-idx="2"]');
            const mid = document.querySelector('[data-commit-idx="1"]');
            const checkbox = anchor.querySelector('[data-testid="commit-select-toggle"]');

            checkbox.dispatchEvent(new MouseEvent('mousedown', { button: 0, bubbles: true }));
            // Extend to the oldest row, then walk back toward anchor.
            far.dispatchEvent(new PointerEvent('pointerover', { bubbles: true, buttons: 1 }));
            mid.dispatchEvent(new PointerEvent('pointerover', { bubbles: true, buttons: 1 }));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
        })()
    JS);

    $page->page()->getByText('2 selected')->waitFor();
});

test('pressing without drag falls back to the single-toggle click', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    // No pointerover between mousedown and pointerup → regular click, regular toggle.
    $row = $page->page()->locator('[data-testid="commit-row"]')->filter(['hasText' => 'Add greet function']);
    $row->hover();
    $row->getByTestId('commit-select-toggle')->click();

    $page->page()->getByText('1 selected')->waitFor();
});

test('drag ended by out-of-window release does not eat the next intentional click', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    // Simulate: press on anchor, drag to another row, then release outside the
    // window. Re-entering the window fires a pointerover with buttons===0 —
    // that's the recovery path. After it, a real user click must still fire.
    $clickFired = $page->script(<<<'JS'
        (() => {
            const anchor = document.querySelector('[data-commit-idx="0"]');
            const far = document.querySelector('[data-commit-idx="2"]');
            const checkbox = anchor.querySelector('[data-testid="commit-select-toggle"]');

            checkbox.dispatchEvent(new MouseEvent('mousedown', { button: 0, bubbles: true }));
            far.dispatchEvent(new PointerEvent('pointerover', { bubbles: true, buttons: 1 }));
            // No pointerup — simulates release outside the window. Cursor
            // re-enters; buttons===0 drives the recovery branch.
            anchor.dispatchEvent(new PointerEvent('pointerover', { bubbles: true, buttons: 0 }));

            let fired = false;
            const probe = document.createElement('button');
            probe.addEventListener('click', () => { fired = true; });
            document.body.appendChild(probe);
            probe.click();
            probe.remove();
            return fired;
        })()
    JS);

    expect($clickFired)->toBeTrue();
    // The drag itself still selected the range.
    $page->page()->getByText('3 selected')->waitFor();
});

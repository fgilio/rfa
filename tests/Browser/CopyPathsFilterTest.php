<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('the bulk copy button copies only the files left visible by an active filter', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('review-component')->first()->waitFor();

    // The default repo has three changed files: hello.php (modified),
    // utils.php (added), config.php (deleted). Wait for the client-hydrated
    // visible list before filtering.
    $page->page()->waitForFunction(<<<'JS'
        () => {
            const root = document.querySelector('[data-testid="review-component"]');
            if (!root) return false;
            return Alpine.$data(root).visibleFileEntries.length >= 2;
        }
    JS);

    // Capture the copy payload at the window rather than reading the real
    // clipboard: headless clipboard access is permission-gated and beside the
    // point. What matters is which paths the button decided to copy.
    $page->page()->evaluate(<<<'JS'
        window.__lastCopy = null;
        window.addEventListener('copy-to-clipboard', (e) => { window.__lastCopy = e.detail; });
    JS);

    // Narrow to hello.php; utils.php and config.php drop out of the
    // server-computed visible set behind the live filter.
    $page->page()->getByPlaceholder('Filter files...')->fill('hello');

    $page->page()->waitForFunction(<<<'JS'
        () => {
            const root = document.querySelector('[data-testid="review-component"]');
            if (!root) return false;
            const entries = Alpine.$data(root).visibleFileEntries;
            return entries.length === 1 && entries[0].path === 'hello.php';
        }
    JS);

    $page->page()->getByTestId('sidebar-copy-paths-trigger')->click();

    $page->page()->waitForFunction('window.__lastCopy !== null');

    // The copy reflects the filter: only the visible file, never the hidden ones.
    expect($page->page()->evaluate('window.__lastCopy.text'))->toBe('hello.php');
});

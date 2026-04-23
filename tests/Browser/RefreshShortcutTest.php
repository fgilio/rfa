<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

/**
 * Replace the Alpine refresh() with a counter so Meta+R doesn't actually
 * reload the document mid-test. window.location.reload itself is read-only
 * and can't be stubbed, so we swap the caller instead.
 */
function stubRefreshCounter($page): void
{
    $page->page()->evaluate(<<<'JS'
        window.__refreshCalls = 0;
        const el = document.querySelector('[data-testid="change-polling"]');
        Alpine.$data(el).refresh = () => { window.__refreshCalls += 1; };
    JS);
}

test('the header shows a refresh button labelled with the ⌘R shortcut', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('Refresh page · ⌘R')->first()->waitFor();
    expect($page->page()->getByLabel('Refresh page · ⌘R')->count())->toBeGreaterThan(0);
});

test('pressing ⌘R triggers the refresh handler', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // Waiting for the button confirms the x-init keymap registration ran.
    $page->page()->getByLabel('Refresh page · ⌘R')->first()->waitFor();

    stubRefreshCounter($page);

    $page->page()->locator('body')->press('Meta+r');

    $page->page()->waitForFunction('window.__refreshCalls >= 1');
    expect($page->page()->evaluate('window.__refreshCalls'))->toBeGreaterThan(0);
});

test('⌘R fires even while a comment input is focused', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByLabel('Refresh page · ⌘R')->first()->waitFor();

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $input = $page->page()->getByPlaceholder('Write a comment', false);
    $input->waitFor();
    $input->focus();

    stubRefreshCounter($page);

    $input->press('Meta+r');

    $page->page()->waitForFunction('window.__refreshCalls >= 1');
    expect($page->page()->evaluate('window.__refreshCalls'))->toBeGreaterThan(0);
});

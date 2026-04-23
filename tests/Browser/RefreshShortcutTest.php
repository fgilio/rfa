<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('the header shows a refresh button labelled with the ⌘R shortcut', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('Refresh page · ⌘R')->first()->waitFor();
    expect($page->page()->getByLabel('Refresh page · ⌘R')->count())->toBeGreaterThan(0);
});

test('pressing ⌘R triggers the refresh handler', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // Wait for the refresh control (and therefore its x-init keymap registration) to mount.
    $page->page()->getByLabel('Refresh page · ⌘R')->first()->waitFor();

    // Swap the Alpine component's refresh() for a counter so we don't actually
    // reload the document mid-test (window.location.reload is read-only and
    // can't be stubbed).
    $page->page()->evaluate(<<<'JS'
        window.__refreshCalls = 0;
        const el = document.querySelector('[data-testid="change-polling"]');
        Alpine.$data(el).refresh = () => { window.__refreshCalls += 1; };
    JS);

    $page->page()->locator('body')->press('Meta+r');

    $page->page()->waitForFunction('window.__refreshCalls >= 1');
    expect($page->page()->evaluate('window.__refreshCalls'))->toBeGreaterThan(0);
});

test('⌘R fires even while a comment input is focused', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByLabel('Refresh page · ⌘R')->first()->waitFor();

    // Open a comment form so an input is focused.
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $input = $page->page()->getByPlaceholder('Write a comment', false);
    $input->waitFor();
    $input->focus();

    $page->page()->evaluate(<<<'JS'
        window.__refreshCalls = 0;
        const el = document.querySelector('[data-testid="change-polling"]');
        Alpine.$data(el).refresh = () => { window.__refreshCalls += 1; };
    JS);

    $input->press('Meta+r');

    $page->page()->waitForFunction('window.__refreshCalls >= 1');
    expect($page->page()->evaluate('window.__refreshCalls'))->toBeGreaterThan(0);
});

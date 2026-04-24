<?php

use Pest\Browser\Api\PendingAwaitablePage;

beforeEach(function () {
    $this->setUpTestRepo();
});

const REFRESH_LABEL = 'Refresh · ⌘R · ⌘⇧R to hard reload';

/**
 * Replace the registered keymap handlers for ⌘R / ⌘⇧R with counters so the
 * keyboard shortcuts don't actually round-trip through Livewire or reload the
 * document mid-test. Stubbing at the keymap layer (not via `Alpine.$data` set)
 * avoids triggering Alpine's reactive setter side-effects.
 */
function stubRefreshCounters(PendingAwaitablePage $page): void
{
    $page->page()->evaluate(<<<'JS'
        window.__softRefreshCalls = 0;
        window.__hardReloadCalls = 0;
        const bindings = Alpine.store('keymap').bindings;
        bindings.set('⌘R', { handler: () => { window.__softRefreshCalls += 1; }, allowInEditable: true });
        bindings.set('⌘⇧R', { handler: () => { window.__hardReloadCalls += 1; }, allowInEditable: true });
    JS);
}

test('the header shows a refresh button with ⌘R shortcut hint', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel(REFRESH_LABEL)->first()->waitFor();
    expect($page->page()->getByLabel(REFRESH_LABEL)->count())->toBeGreaterThan(0);
});

test('pressing ⌘R triggers the soft-refresh handler', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByLabel(REFRESH_LABEL)->first()->waitFor();

    stubRefreshCounters($page);

    $page->page()->locator('body')->press('Meta+r');

    $page->page()->waitForFunction('window.__softRefreshCalls >= 1');
    expect($page->page()->evaluate('window.__softRefreshCalls'))->toBeGreaterThan(0);
    expect($page->page()->evaluate('window.__hardReloadCalls'))->toBe(0);
});

test('pressing ⌘⇧R triggers the hard-reload handler', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByLabel(REFRESH_LABEL)->first()->waitFor();

    stubRefreshCounters($page);

    $page->page()->locator('body')->press('Meta+Shift+r');

    $page->page()->waitForFunction('window.__hardReloadCalls >= 1');
    expect($page->page()->evaluate('window.__hardReloadCalls'))->toBeGreaterThan(0);
    expect($page->page()->evaluate('window.__softRefreshCalls'))->toBe(0);
});

test('⌘R fires even while a comment input is focused', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByLabel(REFRESH_LABEL)->first()->waitFor();

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $input = $page->page()->getByPlaceholder('Write a comment', false);
    $input->waitFor();
    $input->focus();

    stubRefreshCounters($page);

    $input->press('Meta+r');

    $page->page()->waitForFunction('window.__softRefreshCalls >= 1');
    expect($page->page()->evaluate('window.__softRefreshCalls'))->toBeGreaterThan(0);
});

<?php

use App\Events\ToggleSidebarRequested;

/**
 * Sidebar visibility: the hyper+S shortcut, the header button, and the
 * persistence of the collapsed state — on both surfaces that render
 * <x-resizable-sidebar-shell> (review and context).
 */
beforeEach(function () {
    $this->setUpTestRepo();
});

/** Playwright spelling of the catalog's ⌃⌥⇧⌘S. */
const HYPER_S = 'Control+Alt+Shift+Meta+KeyS';

/** The persisted flag behind the sidebar, read straight off the Alpine store. */
const COLLAPSED = 'Alpine.store("settings").sidebarCollapsed';

test('hyper+S hides and restores the review sidebar', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('sidebar')->waitFor();

    $page->page()->locator('body')->press(HYPER_S);
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);
    $page->page()->getByTestId('sidebar-resize-handle')->waitFor(['state' => 'hidden']);

    $page->page()->locator('body')->press(HYPER_S);
    $page->page()->getByTestId('sidebar')->waitFor();
    $page->page()->getByTestId('sidebar-resize-handle')->waitFor();

    expect($page->page()->evaluate(COLLAPSED))->toBeFalse();
});

test('hyper+S hides the context sidebar too', function () {
    $page = $this->visitAndLoad($this->projectUrl().'/context');

    $page->page()->getByTestId('sidebar')->waitFor();

    $page->page()->locator('body')->press(HYPER_S);
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    expect($page->page()->evaluate(COLLAPSED))->toBeTrue();
});

test('the header button toggles the sidebar and swaps with its counterpart', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('sidebar')->waitFor();
    $page->page()->getByTestId('sidebar-show')->waitFor(['state' => 'hidden']);

    $page->page()->getByTestId('sidebar-hide')->click();

    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);
    $page->page()->getByTestId('sidebar-hide')->waitFor(['state' => 'hidden']);

    $page->page()->getByTestId('sidebar-show')->click();

    $page->page()->getByTestId('sidebar')->waitFor();

    expect($page->page()->evaluate(COLLAPSED))->toBeFalse();
});

test('the collapsed state survives navigation and a reload', function () {
    // Both hops stay in the same page (and so the same localStorage) on purpose:
    // each visit() gets a fresh browser context, which would drop $persist state
    // and make this test prove nothing.
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('sidebar')->waitFor();
    $page->page()->getByTestId('sidebar-hide')->click();
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    $page->page()->getByRole('link', ['name' => 'Context'])->click();
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    // The keymap store drops every binding on `livewire:navigating`, so prove
    // the shell re-registered after the swap instead of only that the store
    // value survived it.
    $page->page()->locator('body')->press(HYPER_S);
    $page->page()->getByTestId('sidebar')->waitFor();
    $page->page()->locator('body')->press(HYPER_S);
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    $page->page()->reload();
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    expect($page->page()->evaluate(COLLAPSED))->toBeTrue();
});

test('hyper+S still fires while the caret is in a text field', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('sidebar')->waitFor();

    // allowInEditable: a chrome command must not go dead because the user is
    // mid-keystroke in the filter.
    $filter = $page->page()->getByPlaceholder('Filter files...');
    $filter->click();
    $filter->press(HYPER_S);

    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    expect($page->page()->evaluate(COLLAPSED))->toBeTrue();
});

test('the sidebar is reachable at the minimum window width', function () {
    // The window floor is 800px (NativeAppServiceProvider::createWindow). The
    // sidebar used to be breakpoint-gated at lg (1024px), which left the toggle
    // writing state it could not show anywhere in between.
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->setViewportSize(800, 600);

    $page->page()->getByTestId('sidebar')->waitFor();

    $page->page()->locator('body')->press(HYPER_S);
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    $page->page()->locator('body')->press(HYPER_S);
    $page->page()->getByTestId('sidebar')->waitFor();

    expect($page->page()->evaluate(COLLAPSED))->toBeFalse();
});

test('a bare s never toggles the sidebar, in the filter or out of it', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('sidebar')->waitFor();

    // Only the full hyper chord binds: an unmodified 's' is not a shortcut, and
    // in the filter it has to reach the input as a character.
    $page->page()->locator('body')->press('s');
    $page->page()->getByTestId('sidebar')->waitFor();

    $filter = $page->page()->getByPlaceholder('Filter files...');
    $filter->press('s');

    $page->page()->getByTestId('sidebar')->waitFor();
    expect($filter->inputValue())->toBe('s');
});

test('the native View-menu item reaches the sidebar through the layout bridge', function () {
    // The PHP side only proves the event is broadcast; a typo in the layout's
    // listener name or in the window event it re-dispatches would keep every
    // other test green. This drives the real chain end to end, and takes the
    // event name from the class, so a rename that misses the layout fails here.
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('sidebar')->waitFor();

    $page->page()->evaluate(sprintf(
        'window.Livewire.dispatch(%s)',
        json_encode('native:'.ToggleSidebarRequested::class),
    ));

    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    expect($page->page()->evaluate(COLLAPSED))->toBeTrue();
});

test('holding hyper+S toggles once, not once per auto-repeat', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('sidebar')->waitFor();

    // Playwright's press() cannot hold a key down, so the auto-repeat keydowns
    // are synthesized against the real registered binding. Without the
    // ignoreAutoRepeat guard these four events flip the sidebar four times and
    // leave it exactly where it started.
    $page->page()->evaluate(<<<'JS'
        (() => {
            const press = (repeat) => window.dispatchEvent(new KeyboardEvent('keydown', {
                key: 's',
                code: 'KeyS',
                ctrlKey: true,
                altKey: true,
                shiftKey: true,
                metaKey: true,
                repeat,
                bubbles: true,
                cancelable: true,
            }));

            press(false);
            press(true);
            press(true);
            press(true);
        })()
        JS);

    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    expect($page->page()->evaluate(COLLAPSED))->toBeTrue();
});

test('the pre-paint class hides a collapsed sidebar and is handed back to x-show', function () {
    // Alpine boots with Livewire at the end of <body>, so a persisted collapsed
    // sidebar would paint at 288px for a frame. settings-store.js marks <html>
    // from the head instead, then drops the mark on alpine:initialized — if it
    // ever stopped dropping it, an expanded sidebar would stay hidden forever.
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('sidebar')->waitFor();
    $page->page()->getByTestId('sidebar-hide')->click();
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    $page->page()->reload();
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);

    $bootClass = 'document.documentElement.classList.contains("rfa-boot-sidebar-collapsed")';

    expect($page->page()->evaluate($bootClass))->toBeFalse();

    $page->page()->locator('body')->press(HYPER_S);
    $page->page()->getByTestId('sidebar')->waitFor();

    // And prove the rule actually matches the markup it targets: with the class
    // back on, CSS alone hides an expanded sidebar. Renaming either the class or
    // data-sidebar-collapsible without the other breaks this and nothing else.
    $page->page()->evaluate('document.documentElement.classList.add("rfa-boot-sidebar-collapsed")');
    $page->page()->getByTestId('sidebar')->waitFor(['state' => 'hidden']);
    $page->page()->getByTestId('sidebar-resize-handle')->waitFor(['state' => 'hidden']);

    $page->page()->evaluate('document.documentElement.classList.remove("rfa-boot-sidebar-collapsed")');
    $page->page()->getByTestId('sidebar')->waitFor();

    expect($page->page()->evaluate(COLLAPSED))->toBeFalse();
});

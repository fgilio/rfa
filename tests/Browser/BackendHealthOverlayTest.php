<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

// -- Initial state --

test('overlay is hidden during normal operation', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    expect($page->script("Alpine.store('backendHealth').state"))
        ->toBe('connected');

    $page->page()->getByTestId('backend-health-overlay')
        ->waitFor(['state' => 'hidden']);
});

// -- Strike-counter semantics --

test('single failure does not flip the overlay', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("Alpine.store('backendHealth').reportFailure('first blip')");

    expect($page->script("Alpine.store('backendHealth').state"))
        ->toBe('connected');

    $page->page()->getByTestId('backend-health-overlay')
        ->waitFor(['state' => 'hidden']);
});

test('two consecutive failures flip the overlay to reconnecting', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // Stub the auto-recovery probe specifically so it fails — otherwise it'd hit
    // the live /_rfa/health, succeed, and immediately reload the page back to
    // `connected`, making the assertion racy. Other fetches (including anything
    // Playwright or Livewire need) pass through.
    $page->script("
        const realFetch = window.fetch.bind(window);
        window.fetch = (input, init) => {
            let url = '';
            if (typeof input === 'string') { url = input; }
            else if (input && input.url) { url = input.url; }
            if (url && url.includes('/_rfa/health')) {
                return Promise.reject(new Error('stubbed for test'));
            }
            return realFetch(input, init);
        };
    ");

    $page->script("Alpine.store('backendHealth').reportFailure('first')");
    $page->script("Alpine.store('backendHealth').reportFailure('second')");

    $page->page()->getByTestId('backend-health-overlay')->waitFor();

    expect($page->script("Alpine.store('backendHealth').state"))
        ->toBe('reconnecting');

    $page->assertSee('Reconnecting to backend');
});

// -- Unrecoverable state UI --

test('unrecoverable state surfaces force-quit and restart buttons', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("Alpine.store('backendHealth').flipUnrecoverable('Backend gave up after 5 crashes.')");

    $page->page()->getByTestId('backend-health-overlay')->waitFor();
    $page->page()->getByTestId('backend-health-force-quit')->waitFor();
    $page->page()->getByTestId('backend-health-restart')->waitFor();

    $page->assertSee('Backend won');           // "Backend won't recover" (apostrophe is a smart quote)
    $page->assertSee('Backend gave up after 5 crashes.');
});

test('clicking force-quit calls window.rfaLifecycle.forceQuit when present', function () {
    // window.rfaLifecycle is only injected by Electron's preload in the
    // packaged app. In the test browser it doesn't exist, so we install a stub
    // and verify the overlay routes through it.
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("
        window.rfaLifecycle = {
            __forceQuitCalled: 0,
            forceQuit() { this.__forceQuitCalled += 1; },
            __restartCalled: 0,
            restart() { this.__restartCalled += 1; },
        };
        Alpine.store('backendHealth').flipUnrecoverable('test');
    ");

    $page->page()->getByTestId('backend-health-force-quit')->click();

    expect($page->script('window.rfaLifecycle.__forceQuitCalled'))->toBe(1);
});

test('clicking restart calls window.rfaLifecycle.restart when present', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("
        window.rfaLifecycle = {
            __forceQuitCalled: 0,
            forceQuit() { this.__forceQuitCalled += 1; },
            __restartCalled: 0,
            restart() { this.__restartCalled += 1; },
        };
        Alpine.store('backendHealth').flipUnrecoverable('test');
    ");

    $page->page()->getByTestId('backend-health-restart')->click();

    expect($page->script('window.rfaLifecycle.__restartCalled'))->toBe(1);
});

test('action button disables itself after the first click', function () {
    // Playwright's click() waits for the element to be "actionable" (enabled),
    // so we can't easily simulate a panic double-click directly — Playwright
    // would block on the disabled state. Instead, click once and assert the
    // button is now disabled. The disabled binding + the in-method guard
    // (if (this.actionInFlight) return;) together prevent the double-fire.
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("
        window.rfaLifecycle = {
            __forceQuitCalled: 0,
            forceQuit() { this.__forceQuitCalled += 1; },
            restart() {},
        };
        Alpine.store('backendHealth').flipUnrecoverable('test');
    ");

    $forceQuitButton = $page->page()->getByTestId('backend-health-force-quit');
    $forceQuitButton->click();

    expect($page->script('window.rfaLifecycle.__forceQuitCalled'))->toBe(1);
    expect($forceQuitButton->isDisabled())->toBeTrue();
});

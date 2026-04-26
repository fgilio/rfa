<?php

// Regression tests: confirm the disconnect detection is narrow enough that
// generic browser activity (failed images, aborted fetches, non-Livewire HTTP
// noise) doesn't trip the overlay. If anyone later switches to global window.fetch
// wrapping or starts listening to ResourceTiming/PerformanceObserver events,
// these tests catch the resulting false-positive flips.

beforeEach(function () {
    $this->setUpTestRepo();
});

test('non-Livewire browser noise does not flip the overlay', function (string $script) {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script($script);

    expect($page->script("Alpine.store('backendHealth').state"))
        ->toBe('connected');
})->with([
    'image 404' => [
        "const img = document.createElement('img');"
        ." img.src = '/no-such-image-' + Date.now() + '.png';"
        .' document.body.appendChild(img);',
    ],
    'non-Livewire fetch failure' => [
        // Fire and forget — store has no global fetch hook, so a failed fetch
        // can't reach it whether we wait or not.
        "fetch('/no-such-endpoint-' + Date.now()).then(() => {}).catch(() => {});",
    ],
    'AbortController-aborted fetch' => [
        'const controller = new AbortController();'
        ." fetch('/_rfa/health', { signal: controller.signal }).catch(() => {});"
        .' controller.abort();',
    ],
    'failed link rel=preload' => [
        "const link = document.createElement('link');"
        ." link.rel = 'preload';"
        ." link.as = 'script';"
        ." link.href = '/no-such-preload-' + Date.now() + '.js';"
        .' document.head.appendChild(link);',
    ],
]);

test('a successful page load with active store keeps state at connected', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    expect($page->script("Alpine.store('backendHealth').state"))
        ->toBe('connected');
});

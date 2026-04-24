<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

/**
 * End-to-end proof that public/js/session-recovery.js beats Livewire's
 * built-in 419 handler (native confirm() then opt-in reload) to a silent reload.
 *
 * Laravel's VerifyCsrfToken middleware bypasses validation when running under
 * the test suite, so we cannot force a real 419 through the middleware here.
 * Instead we stub window.fetch to return 419 for the first Livewire update.
 * The response still flows through Livewire's real client-side request
 * lifecycle, so the interceptor sees a genuine 419 Response object exactly
 * the way it will in production. Only the server round-trip is faked.
 *
 * Signals:
 * - beforeunload fires only if the browser actually navigates away. Its
 *   sessionStorage entry surviving the reload proves the silent reload ran.
 * - window.confirm is trapped. Livewire's default 419 path calls confirm()
 *   first; our interceptor's preventDefault() should beat it. If the trap
 *   fires, the interceptor regressed.
 *
 * Note: the Pest-browser wrapper's waitForFunction is not a poll (it's a
 * single evaluate), so we poll from PHP instead. The loop tolerates the
 * execution-context-destroyed errors that naturally happen during the reload.
 */
function waitForSessionValue($page, string $key, string $expected, float $timeoutSec = 5.0): ?string
{
    $deadline = microtime(true) + $timeoutSec;
    $latest = null;

    while (microtime(true) < $deadline) {
        try {
            $latest = $page->page()->evaluate("sessionStorage.getItem('{$key}')");
            if ($latest === $expected) {
                return $latest;
            }
        } catch (Throwable) {
            // Navigation in flight destroyed the context. Retry.
        }
        usleep(100_000);
    }

    return $latest;
}

/**
 * Read a sessionStorage value once, retrying on the "execution context was
 * destroyed" errors that naturally fire while a reload navigation is in
 * flight. `waitForLoadState('load')` only guarantees the current page is
 * loaded — browser-internal navigations or late beforeunload→load cycles can
 * still destroy the context between two statements. Raises the last caught
 * error if every attempt within $timeoutSec fails.
 */
function readSessionValue($page, string $key, float $timeoutSec = 3.0): ?string
{
    $deadline = microtime(true) + $timeoutSec;
    $lastError = null;

    while (microtime(true) < $deadline) {
        try {
            return $page->page()->evaluate("sessionStorage.getItem('{$key}')");
        } catch (Throwable $e) {
            $lastError = $e;
            usleep(100_000);
        }
    }

    throw $lastError ?? new RuntimeException("readSessionValue('{$key}') timed out");
}

test('interceptor silently reloads on 419 instead of showing Livewire confirm dialog', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->waitForFunction('typeof Livewire !== "undefined"');

    $page->page()->evaluate(<<<'JS'
        () => {
            sessionStorage.clear();

            window.addEventListener('beforeunload', () => {
                sessionStorage.setItem('__csrf419ReloadRan', '1');
            });

            window.confirm = () => {
                sessionStorage.setItem('__csrf419Dialog', '1');
                return false;
            };

            const origFetch = window.fetch;
            window.fetch = function (input, init) {
                const url = typeof input === 'string' ? input : input?.url;
                if (url && url.includes('/livewire') && url.includes('/update')) {
                    window.fetch = origFetch;
                    return Promise.resolve(new Response('', { status: 419 }));
                }
                return origFetch.call(this, input, init);
            };
        }
    JS);

    $page->page()->evaluate('() => { Livewire.first().$refresh(); }');

    expect(waitForSessionValue($page, '__csrf419ReloadRan', '1'))->toBe('1');

    // beforeunload fired, but the reload navigation may still be in flight.
    // Wait for it to settle before reading sessionStorage again, and retry
    // the read because late nav cycles can still destroy the context.
    $page->page()->waitForLoadState('load');

    expect(readSessionValue($page, '__csrf419Dialog'))->toBeNull();
});

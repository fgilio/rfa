import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import smartPoll from '../../public/js/smart-poll.js';

const { parseDuration, createDirectiveHandler, install } = smartPoll;

// happy-dom's `document.hasFocus` returns true by default and `document.hidden`
// is false. We override per test via `vi.spyOn` so we can drive focus
// transitions deterministically.
function setFocus(focused) {
    vi.spyOn(document, 'hasFocus').mockReturnValue(focused);
}

function setHidden(hidden) {
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => hidden,
    });
}

describe('parseDuration', () => {
    it.each([
        ['10s', 10_000],
        ['5m', 300_000],
        ['2h', 7_200_000],
        ['250ms', 250],
        ['  30s  ', 30_000],
        // Default unit matches Livewire's `wire:poll` (ms).
        ['1500', 1500],
    ])('parses %j as %i ms', (input, expected) => {
        expect(parseDuration(input)).toBe(expected);
    });

    it.each([null, undefined, '', 'abc', '5x', 's', '-1s', '1.5s'])(
        'returns null for invalid input %j',
        (input) => {
            expect(parseDuration(input)).toBeNull();
        }
    );
});

describe('createDirectiveHandler', () => {
    let cleanupCallbacks;
    let component;
    let el;
    let attach;

    beforeEach(() => {
        vi.useFakeTimers();
        setFocus(true);
        setHidden(false);

        cleanupCallbacks = [];
        component = {
            $wire: { call: vi.fn(() => Promise.resolve()) },
        };
        el = document.createElement('div');
        document.body.appendChild(el);

        const handler = createDirectiveHandler({ window, document });
        attach = (overrides = {}) => {
            handler({
                el,
                directive: { expression: 'poll' },
                component,
                cleanup: (cb) => cleanupCallbacks.push(cb),
                ...overrides,
            });
        };
    });

    afterEach(() => {
        cleanupCallbacks.forEach((cb) => cb());
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('calls the component method on the focused interval', async () => {
        el.dataset.focus = '10s';
        el.dataset.blur = '5m';

        attach();

        expect(component.$wire.call).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(9_999);
        expect(component.$wire.call).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);
        expect(component.$wire.call).toHaveBeenCalledWith('poll');
    });

    it('uses the blur interval when window is unfocused', async () => {
        el.dataset.focus = '10s';
        el.dataset.blur = '5m';
        setFocus(false);

        attach();

        await vi.advanceTimersByTimeAsync(10_000);
        expect(component.$wire.call).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(290_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);
    });

    it('pauses entirely when document is hidden', async () => {
        el.dataset.focus = '10s';
        el.dataset.blur = '5m';
        setHidden(true);

        attach();

        await vi.advanceTimersByTimeAsync(10 * 60 * 1000);
        expect(component.$wire.call).not.toHaveBeenCalled();
    });

    it('fires immediate tick when window regains focus', async () => {
        el.dataset.focus = '10s';
        el.dataset.blur = '5m';
        setFocus(false);

        attach();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(component.$wire.call).not.toHaveBeenCalled();

        setFocus(true);
        window.dispatchEvent(new Event('focus'));

        // setTimeout(0) — the immediate tick goes through the same
        // `inflight` guard so we still need to flush microtasks/timers.
        await vi.advanceTimersByTimeAsync(0);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);
    });

    it('re-reads data attributes on every tick', async () => {
        el.dataset.focus = '10s';
        el.dataset.blur = '5m';

        attach();

        // Simulate a Livewire morph BEFORE the first tick: the in-flight
        // setTimeout was scheduled with the old '10s', so it still fires
        // after 10s. The schedule() call _inside_ that first tick then
        // re-reads the morphed `data-focus` for the next interval.
        el.dataset.focus = '2s';

        await vi.advanceTimersByTimeAsync(10_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(2_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(2);
    });

    it('does not stack ticks while a request is in flight', async () => {
        el.dataset.focus = '10s';
        el.dataset.blur = '5m';

        let resolveCall;
        component.$wire.call = vi.fn(
            () => new Promise((resolve) => {
                resolveCall = resolve;
            })
        );

        attach();

        await vi.advanceTimersByTimeAsync(10_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);

        // While the first call is still inflight, advancing by another
        // interval shouldn't fire a second call.
        await vi.advanceTimersByTimeAsync(20_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);

        resolveCall();
        await vi.advanceTimersByTimeAsync(0);
        await vi.advanceTimersByTimeAsync(10_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(2);
    });

    it('defaults to "poll" when no expression is given', async () => {
        el.dataset.focus = '10s';
        attach({ directive: { expression: '' } });

        await vi.advanceTimersByTimeAsync(10_000);
        expect(component.$wire.call).toHaveBeenCalledWith('poll');
    });

    it('omitting data-blur pauses while unfocused', async () => {
        el.dataset.focus = '10s';
        // intentionally no data-blur
        setFocus(false);

        attach();

        await vi.advanceTimersByTimeAsync(60 * 60 * 1000);
        expect(component.$wire.call).not.toHaveBeenCalled();

        // Refocusing should resume polling immediately.
        setFocus(true);
        window.dispatchEvent(new Event('focus'));
        await vi.advanceTimersByTimeAsync(0);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);
    });

    it('cleanup detaches all listeners and clears timer', async () => {
        el.dataset.focus = '10s';
        attach();

        await vi.advanceTimersByTimeAsync(10_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);

        cleanupCallbacks.forEach((cb) => cb());

        // No more ticks after cleanup.
        await vi.advanceTimersByTimeAsync(60_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);

        // Focus events shouldn't trigger anything either.
        setFocus(false);
        window.dispatchEvent(new Event('blur'));
        setFocus(true);
        window.dispatchEvent(new Event('focus'));
        await vi.advanceTimersByTimeAsync(60_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);
    });

    it('survives a method that throws and continues polling', async () => {
        el.dataset.focus = '10s';
        component.$wire.call = vi.fn(() => Promise.reject(new Error('boom')));

        attach();

        await vi.advanceTimersByTimeAsync(10_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(10_000);
        expect(component.$wire.call).toHaveBeenCalledTimes(2);
    });
});

describe('install', () => {
    afterEach(() => {
        delete window.Livewire;
        delete window.__smartPollAttached;
    });

    it('registers wire:smart-poll with Livewire and is idempotent', () => {
        const directive = vi.fn();
        window.Livewire = { directive };

        expect(install(window)).toBe(true);
        expect(directive).toHaveBeenCalledTimes(1);
        expect(directive).toHaveBeenCalledWith('smart-poll', expect.any(Function));

        expect(install(window)).toBe(false);
        expect(directive).toHaveBeenCalledTimes(1);
    });

    it('is a no-op when Livewire is not present', () => {
        expect(install(window)).toBe(false);
    });
});

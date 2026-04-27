/**
 * `wire:smart-poll` — focus-aware Livewire poll directive.
 *
 * Drop-in alternative to `wire:poll` that backs off when the window loses
 * focus and pauses entirely when the document is hidden. Intervals are read
 * fresh from `data-focus`/`data-blur` on every tick, so a component re-render
 * (e.g. status-driven intervals on `update-banner`) updates the cadence
 * without re-attaching the directive.
 *
 * Usage:
 *   <div
 *       wire:smart-poll="poll"
 *       data-focus="10s"
 *       data-blur="5m"
 *   ></div>
 *
 * Durations accept the same suffixes as `wire:poll`: `ms`, `s`, `m`, `h`
 * (default `ms` if unspecified, matching Livewire). Missing or unparseable
 * values pause that mode — useful for "only poll when focused".
 *
 * On regaining focus (window focus or tab visibility) we fire one immediate
 * tick before resuming the focused-cadence interval, so foregrounding feels
 * instant instead of waiting up to a full focused interval.
 */
(function () {
    const UNIT_MS = { ms: 1, s: 1000, m: 60000, h: 3600000 };

    function parseDuration(value) {
        if (value === undefined || value === null || value === '') return null;
        const match = String(value).trim().match(/^(\d+)(ms|s|m|h)?$/);
        if (!match) return null;
        const n = parseInt(match[1], 10);
        const unit = match[2] || 'ms';
        return n * UNIT_MS[unit];
    }

    function isFocused() {
        return !document.hidden && document.hasFocus();
    }

    function init() {
        if (typeof window.Livewire === 'undefined' || window.__smartPollAttached) return;
        window.__smartPollAttached = true;

        window.Livewire.directive('smart-poll', ({ el, directive, component, cleanup }) => {
            const method = (directive.expression || '').trim() || 'poll';

            let timeoutId = null;
            let inflight = false;
            let lastFocused = isFocused();

            function readInterval() {
                if (document.hidden) return null;
                const attr = document.hasFocus() ? 'focus' : 'blur';
                return parseDuration(el.dataset[attr]);
            }

            function schedule() {
                clearTimeout(timeoutId);
                timeoutId = null;
                const ms = readInterval();
                if (ms === null) return;
                timeoutId = setTimeout(tick, ms);
            }

            async function tick() {
                if (inflight || document.hidden) {
                    schedule();
                    return;
                }
                inflight = true;
                try {
                    await component.$wire.call(method);
                } catch (e) {
                    // Swallow — Livewire surfaces network errors elsewhere; we just retry next tick.
                } finally {
                    inflight = false;
                }
                schedule();
            }

            function onTransition() {
                const focusedNow = isFocused();
                if (!lastFocused && focusedNow) {
                    lastFocused = true;
                    clearTimeout(timeoutId);
                    timeoutId = null;
                    tick();
                    return;
                }
                lastFocused = focusedNow;
                schedule();
            }

            window.addEventListener('focus', onTransition);
            window.addEventListener('blur', onTransition);
            document.addEventListener('visibilitychange', onTransition);

            schedule();

            cleanup(() => {
                clearTimeout(timeoutId);
                window.removeEventListener('focus', onTransition);
                window.removeEventListener('blur', onTransition);
                document.removeEventListener('visibilitychange', onTransition);
            });
        });
    }

    if (typeof window.Livewire !== 'undefined') {
        init();
    } else {
        document.addEventListener('livewire:init', init);
    }
})();

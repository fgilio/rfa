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
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const UNIT_MS = { ms: 1, s: 1000, m: 60000, h: 3600000 };

    function parseDuration(value) {
        if (value === undefined || value === null || value === '') return null;
        const match = String(value).trim().match(/^(\d+)(ms|s|m|h)?$/);
        if (!match) return null;
        const n = parseInt(match[1], 10);
        const unit = match[2] || 'ms';
        return n * UNIT_MS[unit];
    }

    function isFocused(doc) {
        return !doc.hidden && doc.hasFocus();
    }

    /**
     * Builds the per-element directive callback. Exported separately from the
     * Livewire registration so tests can drive it without spinning up Livewire.
     *
     * @param {object} deps
     * @param {Window}   deps.window
     * @param {Document} deps.document
     */
    function createDirectiveHandler(deps) {
        const win = deps.window;
        const doc = deps.document;

        return function attach({ el, directive, component, cleanup }) {
            const method = ((directive && directive.expression) || '').trim() || 'poll';

            let timeoutId = null;
            let inflight = false;
            let lastFocused = isFocused(doc);

            function readInterval() {
                if (doc.hidden) return null;
                const attr = doc.hasFocus() ? 'focus' : 'blur';
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
                if (inflight || doc.hidden) {
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
                const focusedNow = isFocused(doc);
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

            win.addEventListener('focus', onTransition);
            win.addEventListener('blur', onTransition);
            doc.addEventListener('visibilitychange', onTransition);

            schedule();

            cleanup(() => {
                clearTimeout(timeoutId);
                win.removeEventListener('focus', onTransition);
                win.removeEventListener('blur', onTransition);
                doc.removeEventListener('visibilitychange', onTransition);
            });
        };
    }

    function install(root) {
        if (typeof root.Livewire === 'undefined' || root.__smartPollAttached) return false;
        root.__smartPollAttached = true;
        const handler = createDirectiveHandler({ window: root, document: root.document });
        root.Livewire.directive('smart-poll', handler);
        return true;
    }

    function autoInstall(root) {
        if (root.Livewire) {
            install(root);
        } else {
            root.document.addEventListener('livewire:init', () => install(root));
        }
    }

    return { parseDuration, isFocused, createDirectiveHandler, install, autoInstall };
});

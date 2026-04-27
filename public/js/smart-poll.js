// `wire:smart-poll` — focus-aware Livewire poll directive. The directive's
// public contract lives in `resources/CLAUDE.md` ("Polling"); this file owns
// the timing primitive `startSmartPoll`, which is also exposed on
// `window.smartPoll` for Alpine blocks that poll non-Livewire endpoints.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.smartPoll = api;
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
        const ms = n * UNIT_MS[unit];
        return ms > 0 ? ms : null;
    }

    function isFocused(doc) {
        return !doc.hidden && doc.hasFocus();
    }

    /**
     * Drives a focus-aware polling loop. Pauses while the document is hidden,
     * fires one immediate tick on regaining focus, and serializes overlapping
     * ticks via an inflight guard.
     *
     * @param {object} opts
     * @param {Window}   opts.window
     * @param {Document} opts.document
     * @param {() => number|null} opts.getInterval  ms until next tick, or null to pause.
     * @param {() => Promise<void>|void} opts.onTick
     * @returns {() => void} stop() — clears the timer and detaches listeners.
     */
    function startSmartPoll({ window: win, document: doc, getInterval, onTick }) {
        let timeoutId = null;
        let inflight = false;
        let stopped = false;
        let lastFocused = isFocused(doc);

        function schedule() {
            clearTimeout(timeoutId);
            timeoutId = null;
            if (stopped) return;
            const ms = getInterval();
            if (ms === null) return;
            timeoutId = setTimeout(tick, ms);
        }

        async function tick() {
            if (stopped) return;
            if (inflight || doc.hidden) {
                schedule();
                return;
            }
            inflight = true;
            try {
                await onTick();
            } catch (e) {
                // Caller surfaces errors elsewhere; we just retry next tick.
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

        return function stop() {
            stopped = true;
            clearTimeout(timeoutId);
            timeoutId = null;
            win.removeEventListener('focus', onTransition);
            win.removeEventListener('blur', onTransition);
            doc.removeEventListener('visibilitychange', onTransition);
        };
    }

    function createDirectiveHandler(deps) {
        const win = deps.window;
        const doc = deps.document;

        return function attach({ el, directive, component, cleanup }) {
            const method = ((directive && directive.expression) || '').trim() || 'poll';

            const stop = startSmartPoll({
                window: win,
                document: doc,
                getInterval() {
                    if (doc.hidden) return null;
                    const attr = doc.hasFocus() ? 'focus' : 'blur';
                    return parseDuration(el.dataset[attr]);
                },
                onTick: () => component.$wire.call(method),
            });

            cleanup(stop);
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

    return { parseDuration, isFocused, startSmartPoll, createDirectiveHandler, install, autoInstall };
});

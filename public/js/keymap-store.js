(function () {
    // Global keyboard-shortcut registry. Each overlay registers its toggle key
    // (⌘K / ⌘B / ⌘J) on init; one window listener dispatches.
    //
    // Registration is keyed by the combo string, so re-registrations during
    // hydration overwrite instead of stacking. Components do not need to
    // unregister on teardown for this app — the three overlay panels are
    // always mounted on the review page.
    function init() {
        Alpine.store('keymap', {
            bindings: new Map(),
            /**
             * @param {string} combo       e.g. '⌘K' (also matches Ctrl on non-mac)
             * @param {Function} handler   receives the KeyboardEvent
             * @param {object} [opts]
             * @param {boolean} [opts.allowInEditable=false]  fire even while focused in an input/textarea
             */
            register(combo, handler, { allowInEditable = false } = {}) {
                this.bindings.set(combo, { handler, allowInEditable });
            },
            unregister(combo) {
                this.bindings.delete(combo);
            },
        });

        const matches = (combo, e) => {
            const mod = (e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey;
            if (!mod) return false;
            const key = combo.replace(/^⌘/, '').toLowerCase();
            return e.key.toLowerCase() === key;
        };

        const isEditable = (el) =>
            el?.tagName === 'TEXTAREA' || el?.tagName === 'INPUT' || el?.isContentEditable;

        window.addEventListener('keydown', (e) => {
            const store = Alpine.store('keymap');
            for (const [combo, { handler, allowInEditable }] of store.bindings) {
                if (!matches(combo, e)) continue;
                if (!allowInEditable && isEditable(e.target)) continue;
                e.preventDefault();
                handler(e);
                return;
            }
        });
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

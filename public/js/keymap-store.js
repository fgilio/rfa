// Global keyboard-shortcut registry. Each overlay registers its toggle key
// (⌘K / ⌘B / ⌘J) on init; one window listener dispatches.
//
// Registration is keyed by the combo string, so re-registrations during
// hydration overwrite instead of stacking.
//
// Livewire `wire:navigate` re-executes head scripts, so the attach guard
// is anchored on `window` to survive across script re-evaluations. The
// store itself persists across SPA navigations — `bindings` is cleared on
// `livewire:navigating` so stale shortcuts from the previous page don't
// keep firing (and `preventDefault`-ing native keys) on pages that don't
// re-register them; the incoming page's `x-init` repopulates after swap.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.keymapStore = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    /**
     * @param {string} combo       e.g. '⌘K' (also matches Ctrl on non-mac)
     * @param {KeyboardEvent|{key: string, metaKey?: boolean, ctrlKey?: boolean, shiftKey?: boolean, altKey?: boolean}} e
     * @returns {boolean}
     */
    function matches(combo, e) {
        const wantCmd = combo.includes('⌘');
        const wantShift = combo.includes('⇧');
        const hasCmd = e.metaKey || e.ctrlKey;
        if (wantCmd !== hasCmd) return false;
        if (wantShift !== e.shiftKey) return false;
        if (e.altKey) return false;
        const key = combo.replace(/[⌘⇧]/g, '').toLowerCase();
        return e.key.toLowerCase() === key;
    }

    /**
     * @param {Element|null|undefined} el
     * @returns {boolean}
     */
    function isEditable(el) {
        return el?.tagName === 'TEXTAREA' || el?.tagName === 'INPUT' || !!el?.isContentEditable;
    }

    function install(root) {
        if (root.__keymapAttached) return false;
        root.__keymapAttached = true;

        const store = {
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
        };
        root.Alpine.store('keymap', store);

        root.document.addEventListener('livewire:navigating', () => {
            store.bindings.clear();
        });

        root.addEventListener('keydown', (e) => {
            for (const [combo, { handler, allowInEditable }] of store.bindings) {
                if (!matches(combo, e)) continue;
                if (!allowInEditable && isEditable(e.target)) continue;
                e.preventDefault();
                handler(e);
                return;
            }
        });

        return true;
    }

    function autoInstall(root) {
        if (root.Alpine) {
            install(root);
        } else {
            root.document.addEventListener('alpine:init', () => install(root));
        }
    }

    return { matches, isEditable, install, autoInstall };
});

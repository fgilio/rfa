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
    // Glyphs in a combo that don't equal their KeyboardEvent.key spelling.
    const GLYPH_KEYS = { '↵': 'enter' };

    /**
     * @param {string} stripped  combo with modifier glyphs removed
     * @returns {string}
     */
    function comboKey(stripped) {
        return GLYPH_KEYS[stripped] ?? stripped;
    }

    /**
     * Base-key match for a combo that carries ⌃ or ⌥.
     *
     * Holding Option rewrites `event.key` through the macOS keyboard layout
     * (⌥S arrives as 'ß'), and Hammerspoon's hyper pass-through synthesises
     * exactly that kind of event. `event.code` names the physical key and no
     * modifier can change it, so it is the reliable fallback for letters.
     *
     * @param {string} key  lower-cased base key from the combo
     * @param {KeyboardEvent|{key?: string, code?: string}} e
     * @returns {boolean}
     */
    function baseKeyMatches(key, e) {
        if (typeof e.key === 'string' && e.key.toLowerCase() === key) return true;

        return /^[a-z]$/.test(key) && e.code === `Key${key.toUpperCase()}`;
    }

    /**
     * @param {string} combo       e.g. '⌘K', '⌘↵', '⌃⌥⇧⌘S', or a bare key like 'j' / '⇧C' / '?'
     * @param {KeyboardEvent|{key: string, code?: string, metaKey?: boolean, ctrlKey?: boolean, shiftKey?: boolean, altKey?: boolean}} e
     * @returns {boolean}
     */
    function matches(combo, e) {
        // Combos that name ⌃ or ⌥ (the hyper-style ones) match every modifier
        // flag literally: Ctrl is part of the combo, so the ⌘→Ctrl aliasing the
        // cross-platform dev build relies on below would make them ambiguous.
        if (combo.includes('⌃') || combo.includes('⌥')) {
            if (e.ctrlKey !== combo.includes('⌃')) return false;
            if (e.altKey !== combo.includes('⌥')) return false;
            if (!!e.metaKey !== combo.includes('⌘')) return false;
            if (!!e.shiftKey !== combo.includes('⇧')) return false;

            return baseKeyMatches(comboKey(combo.replace(/[⌃⌥⌘⇧]/g, '')).toLowerCase(), e);
        }

        if (e.altKey) return false;
        const hasCmd = e.metaKey || e.ctrlKey;

        if (combo.includes('⌘')) {
            // Cmd combo: explicit ⌘/⇧ requirements, base key matched case-insensitively.
            if (!hasCmd) return false;
            if (combo.includes('⇧') !== e.shiftKey) return false;
            const key = comboKey(combo.replace(/[⌘⇧]/g, '')).toLowerCase();
            return e.key.toLowerCase() === key;
        }

        // Bare-character combo: no command modifier. The character itself
        // (including its shifted form, 'C' from Shift+C or '?' from Shift+/)
        // is matched case-sensitively, so the glyph encodes the shift state.
        if (hasCmd) return false;
        return e.key === comboKey(combo);
    }

    /**
     * @param {Element|null|undefined} el
     * @returns {boolean}
     */
    function isEditable(el) {
        return el?.tagName === 'TEXTAREA' || el?.tagName === 'INPUT' || !!el?.isContentEditable;
    }

    function install(root) {
        if (typeof root.Alpine === 'undefined' || root.__keymapAttached) return false;
        root.__keymapAttached = true;

        const store = {
            bindings: new Map(),
            /**
             * @param {string} combo       e.g. '⌘K' (also matches Ctrl on non-mac)
             * @param {Function} handler   receives the KeyboardEvent
             * @param {object} [opts]
             * @param {boolean} [opts.allowInEditable=false]  fire even while focused in an input/textarea
             * @param {boolean} [opts.ignoreAutoRepeat=false]  fire only on the first keydown of a held key
             */
            register(combo, handler, { allowInEditable = false, ignoreAutoRepeat = false } = {}) {
                this.bindings.set(combo, { handler, allowInEditable, ignoreAutoRepeat });
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
            for (const [combo, { handler, allowInEditable, ignoreAutoRepeat }] of store.bindings) {
                if (!matches(combo, e)) continue;
                if (!allowInEditable && isEditable(e.target)) continue;
                // A held key streams keydowns. Repeat is what makes the
                // navigation shortcuts feel right (hold `j` to walk the file
                // list), so it stays on by default — but a toggle would flip
                // once per repeat and settle on whichever parity the release
                // lands in. Those opt out and still swallow the chord, so the
                // held keystrokes never reach the page underneath.
                if (ignoreAutoRepeat && e.repeat) {
                    e.preventDefault();

                    return;
                }
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

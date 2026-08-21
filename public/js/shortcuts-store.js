// Keyboard-shortcut catalog bridge. The combo strings and labels are defined
// once in PHP (config/shortcuts.php) and injected onto `window.RFA_SHORTCUTS`
// by the layout. This store exposes them to Alpine so call sites register
// handlers by a stable `id`, never a hard-coded combo:
//
//   $store.shortcuts.register('project-picker.toggle', () => toggle())
//
// `register`/`unregister` delegate to `$store.keymap`, pulling the combo and
// the behaviour flags (`allowInEditable`, `ignoreAutoRepeat`) from the catalog.
// That keeps the keyboard wiring
// and the discoverable cheat sheet reading from a single source, so a combo
// can't drift between where it fires and where it's documented.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.shortcutsStore = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    /**
     * @param {object} map  catalog keyed by id (shape of config('shortcuts.shortcuts'))
     * @param {object} keymap  the `$store.keymap` instance to delegate to
     */
    function createStore(map, keymap) {
        return {
            map,
            /**
             * @param {string} id       catalog id, e.g. 'review.next-file'
             * @param {Function} handler  receives the KeyboardEvent
             */
            register(id, handler) {
                const entry = this.map[id];
                if (!entry) {
                    console.warn(`[shortcuts] unknown id "${id}", not registered`);
                    return;
                }
                keymap().register(entry.combo, handler, {
                    allowInEditable: !!entry.allowInEditable,
                    ignoreAutoRepeat: !!entry.ignoreAutoRepeat,
                });
            },
            unregister(id) {
                const entry = this.map[id];
                if (entry) {
                    keymap().unregister(entry.combo);
                }
            },
        };
    }

    function install(root) {
        if (typeof root.Alpine === 'undefined' || root.__shortcutsAttached) {
            return false;
        }
        root.__shortcutsAttached = true;

        const map = root.RFA_SHORTCUTS || {};
        root.Alpine.store(
            'shortcuts',
            createStore(map, () => root.Alpine.store('keymap')),
        );

        return true;
    }

    function autoInstall(root) {
        if (root.Alpine) {
            install(root);
        } else {
            root.document.addEventListener('alpine:init', () => install(root));
        }
    }

    return { createStore, install, autoInstall };
});

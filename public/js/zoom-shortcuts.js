// Renderer-side ⌘+/⌘-/⌘0 bindings.
//
// Electron 38's role-based zoomIn/zoomOut/resetZoom roles register their
// macOS keyEquivalents but the keystroke fails to fire `webContentsMethod`
// — see electron/electron#19559 and the View-menu comment in
// `NativeAppServiceProvider::createMenu`. The View-menu items are stripped
// of `role` and `accelerator` so the keystrokes flow through to here, and
// these bindings dispatch `rfa-zoom` to the keepalive Livewire component
// which owns the cached zoom factor and the `Window::zoomFactor` call.
//
// Keymap bindings are cleared on `livewire:navigating` (see keymap-store),
// so we re-register on every `livewire:navigated` plus the initial Alpine
// boot.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.zoomShortcuts = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    /** @typedef {{ direction: 'in'|'out'|'reset' }} ZoomDispatch */

    /**
     * @param {Window & { Alpine?: any, Livewire?: { dispatch: (event: string, detail: ZoomDispatch) => void } }} root
     */
    function dispatch(root, direction) {
        return () => root.Livewire?.dispatch('rfa-zoom', { direction });
    }

    /**
     * Idempotent: keymap-store's `register` overwrites by combo, so calling
     * this on every `livewire:navigated` is safe.
     * @param {Window & { Alpine?: any }} root
     */
    function register(root) {
        const keymap = root.Alpine?.store?.('keymap');
        if (!keymap) return false;

        keymap.register('⌘=', dispatch(root, 'in'));
        keymap.register('⌘-', dispatch(root, 'out'));
        keymap.register('⌘0', dispatch(root, 'reset'));
        return true;
    }

    function autoInstall(root) {
        if (root.__zoomShortcutsAttached) return;
        root.__zoomShortcutsAttached = true;

        const onReady = () => register(root);

        if (root.Alpine) {
            onReady();
        } else {
            root.document.addEventListener('alpine:init', onReady);
        }

        root.document.addEventListener('livewire:navigated', onReady);
    }

    return { dispatch, register, autoInstall };
});

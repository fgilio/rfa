// Alpine factory for <x-copy-paths-button>.
//
// Left-click           → copy relative path(s) + toast.
// Right-click / 400ms  → open the 3-option menu (name / relative / full).
//
// Modes:
//   - 'single' — fixed path via init param.
//   - 'bulk'   — inherits sourceFileEntries / fileMatchesFilter / pathBase /
//                buildFullPath / visibleFileCount from the ⚡review-page root.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const LONG_PRESS_MS = 400;
    const KIND_LABEL = { name: 'file name', relative: 'relative path', full: 'full path' };

    function copyPathsButton({ mode = 'bulk', singlePath = '' } = {}) {
        return {
            _mode: mode,
            _singlePath: singlePath,
            _longPressTimer: null,
            _suppressClick: false,

            paths() {
                if (this._mode === 'single') {
                    return this._singlePath ? [this._singlePath] : [];
                }
                return this.sourceFileEntries
                    .filter((f) => this.fileMatchesFilter(f.path, f.id))
                    .map((f) => f.path);
            },

            get primaryLabel() {
                const c = this._mode === 'single' ? 1 : this.visibleFileCount;
                return c <= 1 ? 'Copy relative path' : `Copy ${c} relative paths`;
            },

            copyAs(kind) {
                const paths = this.paths();
                if (paths.length === 0) return;
                const lines = paths.map((p) => {
                    if (kind === 'name') return this.pathBase(p);
                    if (kind === 'full') return this.buildFullPath(p);
                    return p;
                });
                const label = KIND_LABEL[kind] || 'path';
                const toast = paths.length === 1
                    ? `Copied ${label}`
                    : `Copied ${paths.length} ${label}s`;
                this.$dispatch('copy-to-clipboard', {
                    text: lines.join('\n'),
                    toast,
                });
            },

            onClick(event) {
                if (this._suppressClick) {
                    this._suppressClick = false;
                    return;
                }
                if (event.button !== undefined && event.button !== 0) return;
                this.copyAs('relative');
            },

            onMouseDown(event) {
                if (event.button !== 0) return;
                clearTimeout(this._longPressTimer);
                this._longPressTimer = setTimeout(() => {
                    this._suppressClick = true;
                    this.openMenu();
                }, LONG_PRESS_MS);
            },

            cancelLongPress() {
                clearTimeout(this._longPressTimer);
            },

            openMenu() {
                // <ui-dropdown> doesn't expose an open() method directly — its
                // open state lives on the overlay's _popoverable, which Flux
                // attaches in its boot() (see vendor/livewire/flux JS). The
                // dropdown's overlay is its `lastElementChild` — that's how
                // Flux itself locates it. We avoid `[popover]` here because
                // <flux:tooltip>'s content also carries the popover attr.
                const dd = this.$refs.dropdown;
                if (!dd) return;
                const overlay = dd.lastElementChild;
                if (!overlay) return;
                if (overlay._popoverable && typeof overlay._popoverable.setState === 'function') {
                    overlay._popoverable.setState(true);
                    return;
                }
                if (typeof overlay.showPopover === 'function') {
                    try { overlay.showPopover(); } catch (_) {}
                }
            },
        };
    }

    function autoInstall(root) {
        const init = () => root.Alpine.data('copyPathsButton', copyPathsButton);
        root.Alpine ? init() : root.document.addEventListener('alpine:init', init);
    }

    return { copyPathsButton, autoInstall, LONG_PRESS_MS };
});

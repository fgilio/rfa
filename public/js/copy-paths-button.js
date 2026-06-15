// Alpine factory for <x-copy-paths-button>.
//
// Left-click              → copy relative path(s) + toast.
// Right-click / Shift+F10 → open the 3-option menu (name / relative / full).
//
// Modes:
//   - 'single' — fixed path via init param. Self-contained: takes its own
//                repoPath so it works on pages without the ⚡review-page root
//                (e.g. ⚡context-page, which doesn't expose pathBase /
//                buildFullPath).
//   - 'bulk'   — copies the currently server-visible (filtered) files. Inside
//                the review page it reads the review root's live
//                `visibleFileEntries`, the authoritative filtered list. The
//                button's own `data-source-file-entries` is the standalone
//                fallback for pages without a review root. The count always
//                derives from those entries.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const KIND_LABEL = { name: 'file name', relative: 'relative path', full: 'full path' };

    function copyPathsButton({ mode = 'bulk', singlePath = '', repoPath = '' } = {}) {
        return {
            _mode: mode,
            _singlePath: singlePath,
            _repoPath: repoPath,

            _jsonAttr(name, fallback, el) {
                try {
                    return JSON.parse((el ?? this.$root)?.dataset?.[name] || '');
                } catch (_) {
                    return fallback;
                }
            },

            // The review root carries wire:id and is the element Livewire's
            // morph always updates, so its republished `visibleFileEntries` is
            // the authoritative filtered file list. This button sits inside a
            // Flux dropdown island the morph does not re-patch, so its own
            // attribute is read only as the standalone fallback for pages that
            // have no review root.
            get _rootVisibleEntries() {
                const root = this.$el?.closest?.('[data-testid="review-component"]');
                const live = root ? this._jsonAttr('visibleFileEntries', null, root) : null;

                return Array.isArray(live) ? live : null;
            },

            get bulkEntries() {
                const entries = this._rootVisibleEntries ?? this._jsonAttr('sourceFileEntries', null);
                return Array.isArray(entries) ? entries : [];
            },

            // The label ("Copy N paths") and the copied lines must come from one
            // source, or they can disagree. The entries are that source, so the
            // count is their length rather than a separate count attribute that
            // could drift from the list actually copied.
            get bulkVisibleCount() {
                return this.bulkEntries.length;
            },

            paths() {
                if (this._mode === 'single') {
                    return this._singlePath ? [this._singlePath] : [];
                }
                return this.bulkEntries.map((f) => f.path);
            },

            _label(noun) {
                const c = this._mode === 'single' ? 1 : this.bulkVisibleCount;
                return c <= 1 ? `Copy ${noun}` : `Copy ${c} ${noun}s`;
            },
            get nameLabel() { return this._label('file name'); },
            get relativeLabel() { return this._label('relative path'); },
            get fullLabel() { return this._label('full path'); },
            get primaryLabel() { return this.relativeLabel; },

            copyAs(kind) {
                const paths = this.paths();
                if (paths.length === 0) return;
                const repo = (this._repoPath || this.repoPath || '').replace(/\/+$/, '');
                const lines = paths.map((p) => {
                    if (kind === 'name') {
                        const i = p.lastIndexOf('/');
                        return i >= 0 ? p.slice(i + 1) : p;
                    }
                    if (kind === 'full') return repo ? `${repo}/${p}` : p;
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
                if (event.button !== undefined && event.button !== 0) return;
                this.copyAs('relative');
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
        if (root.__copyPathsButtonAttached) return;
        root.__copyPathsButtonAttached = true;
        const init = () => root.Alpine.data('copyPathsButton', copyPathsButton);
        root.Alpine ? init() : root.document.addEventListener('alpine:init', init);
    }

    return { copyPathsButton, autoInstall };
});

// Alpine factory for <x-copy-paths-button>.
//
// Left-click              → copy relative path(s) + toast.
// Right-click / Shift+F10 → open the 3-option menu (name / relative / full).
//
// Modes:
//   - 'single' — fixed path via init param. Copies client-side so it works on
//                pages without the ⚡review-page root (e.g. ⚡context-page, which
//                doesn't expose pathBase / buildFullPath). Takes its own repoPath.
//   - 'bulk'   — copies the currently server-visible (filtered) files. The copy
//                is server-owned: ReviewPage::copyVisiblePaths builds the list
//                from its authoritative visible set, so a filtered copy can never
//                include hidden files and the button never scrapes the DOM for
//                paths. The menu count is read from the review root's live
//                visibleFileEntries, which the morph keeps current.
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

            // Visible (filtered) file count, read from the review root the morph
            // keeps current. This button sits in a Flux dropdown island the morph
            // does not re-patch, so its own DOM can't be trusted for the count.
            get bulkVisibleCount() {
                const root = this.$el?.closest?.('[data-testid="review-component"]');
                if (!root) return 0;
                try {
                    const entries = JSON.parse(root.dataset.visibleFileEntries || '[]');
                    return Array.isArray(entries) ? entries.length : 0;
                } catch (_) {
                    return 0;
                }
            },

            _label(noun) {
                const c = this._mode === 'single' ? 1 : this.bulkVisibleCount;
                return c <= 1 ? `Copy ${noun}` : `Copy ${c} ${noun}s`;
            },
            get nameLabel() { return this._label('file name'); },
            get relativeLabel() { return this._label('relative path'); },
            get fullLabel() { return this._label('full path'); },
            get primaryLabel() { return this.relativeLabel; },

            copy(kind) {
                if (this._mode === 'single') {
                    this.copyAs(kind);
                    return;
                }
                // Bulk: the server owns the visible set, so it builds and copies.
                this.$wire.copyVisiblePaths(kind);
            },

            copyAs(kind) {
                // Single-mode client copy of the one fixed path.
                if (!this._singlePath) return;
                const repo = (this._repoPath || this.repoPath || '').replace(/\/+$/, '');
                const p = this._singlePath;
                let line;
                if (kind === 'name') {
                    const i = p.lastIndexOf('/');
                    line = i >= 0 ? p.slice(i + 1) : p;
                } else if (kind === 'full') {
                    line = repo ? `${repo}/${p}` : p;
                } else {
                    line = p;
                }
                this.$dispatch('copy-to-clipboard', {
                    text: line,
                    toast: `Copied ${KIND_LABEL[kind] || 'path'}`,
                });
            },

            onClick(event) {
                if (event.button !== undefined && event.button !== 0) return;
                this.copy('relative');
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
        // No one-shot guard: an Alpine factory must re-register so a cache-busted
        // script replaces a stale factory after an app update (public/js/CLAUDE.md).
        const init = () => root.Alpine.data('copyPathsButton', copyPathsButton);
        root.Alpine ? init() : root.document.addEventListener('alpine:init', init);
    }

    return { copyPathsButton, autoInstall };
});

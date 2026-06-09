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
//   - 'bulk'   — reads server-visible entries from data attributes when
//                present. Falls back to the ⚡review-page Alpine root.
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

            _jsonAttr(name, fallback) {
                try {
                    return JSON.parse(this.$root?.dataset?.[name] || '');
                } catch (_) {
                    return fallback;
                }
            },

            get bulkEntries() {
                const entries = this._jsonAttr('sourceFileEntries', null);
                if (Array.isArray(entries)) return entries;

                return (this.sourceFileEntries || [])
                    .filter((f) => this.fileMatchesFilter(f.path, f.id));
            },

            get bulkVisibleCount() {
                const rawCount = this.$root?.dataset?.visibleFileCount;
                if (rawCount !== undefined && rawCount !== '') {
                    const count = Number(rawCount);
                    if (Number.isFinite(count)) return count;
                }

                return this.visibleFileCount || 0;
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

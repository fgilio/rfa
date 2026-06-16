// Alpine components for pages/⚡review-page.blade.php: the page root
// (`reviewPage`) and the external-change poller (`reviewChangePoller`).
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.reviewPage = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    // Builds the remote-link context-menu model from a contextmenu event, the
    // page's git config, and the viewport. Pure so the ref/path/disabled rules
    // are unit-testable without a DOM.
    function computeRemoteMenu(detail, config, viewport) {
        const d = detail;
        const margin = 8;
        const menuW = 220;
        const clampX = (menuH) => Math.min(d.clientX, viewport.width - menuW - margin);
        const clampY = (menuH) => Math.min(d.clientY, viewport.height - menuH - margin);

        // A direct link carries its own type/params; no ref math needed.
        if (d.target === 'direct') {
            const menuH = 80;
            return {
                open: true,
                x: clampX(menuH),
                y: clampY(menuH),
                projectSlug: d.projectSlug || config.projectSlug,
                type: d.type,
                params: d.params || {},
                label: d.label || 'on remote',
                disabled: false,
                disabledReason: '',
            };
        }

        const projectBranch = config.projectBranch;
        const diffFrom = config.diffFrom;
        const diffTo = config.diffTo;
        const refNew = diffTo || projectBranch || 'HEAD';
        const refOld = diffTo !== null ? diffFrom : (projectBranch || 'HEAD');
        const pathOld = d.oldPath || d.filePath;
        let type, params, label;
        if (d.target === 'file') {
            type = 'file';
            params = { ref: refNew, path: d.filePath };
            label = 'file';
        } else {
            type = 'line';
            params = {
                ref: d.side === 'old' ? refOld : refNew,
                path: d.side === 'old' ? pathOld : d.filePath,
                start: d.start,
                end: d.end,
            };
            label = (d.end === null || d.end === d.start) ? 'line ' + d.start : 'lines ' + d.start + '-' + d.end;
        }
        // Old-side line links always resolve (refOld is where the file existed).
        // For new-side links we only disable when we're sure: pure working-tree
        // mode for `added`, commit/range mode for `deleted`. /rw/{from} mixes
        // working tree and committed history, so we can't tell which side a
        // status belongs to, so leave it enabled rather than mis-disable.
        const isWorkingTreeOnly = diffTo === null && diffFrom === 'HEAD';
        const isCommitOrRange = diffTo !== null;
        const usesNewSideRef = d.target === 'file' || d.side !== 'old';
        const newSideBroken =
            (d.status === 'added' && isWorkingTreeOnly) ||
            (d.status === 'deleted' && isCommitOrRange);
        const disabled = usesNewSideRef && newSideBroken;
        const disabledReason = disabled
            ? (d.status === 'added' ? 'File not pushed to remote yet' : 'File was removed at this commit')
            : '';
        const menuH = disabled ? 110 : 80;
        return {
            open: true,
            x: clampX(menuH),
            y: clampY(menuH),
            projectSlug: config.projectSlug,
            type, params, label, disabled, disabledReason,
        };
    }

    function createReviewPage(config = {}) {
        return {
            config,
            pendingSaves: 0,
            pendingSavesGuard: null,
            activeFile: config.activeFile ?? null,
            repoPath: config.repoPath ?? '',
            remoteMenu: { open: false, x: 0, y: 0, projectSlug: '', type: '', params: {}, label: '', disabled: false, disabledReason: '' },
            commentScrollPollId: null,
            init() {
                this.pendingSavesGuard = window.rfaPendingSaves?.createPendingSavesGuard({
                    root: window,
                    livewire: Livewire,
                    getWireId: () => this.$root.getAttribute('wire:id'),
                    onPendingSavesChanged: (count) => { this.pendingSaves = count; },
                });

                this.pendingSavesGuard?.attach();
            },
            jsonData(name, fallback) {
                try {
                    return JSON.parse(this.$root?.dataset?.[name] || '');
                } catch (_) {
                    return fallback;
                }
            },
            get sourceFileEntries() {
                return this.jsonData('sourceFileEntries', []);
            },
            get visibleFileEntries() {
                return this.jsonData('visibleFileEntries', []);
            },
            showRemoteMenu($event) {
                this.remoteMenu = computeRemoteMenu(
                    $event.detail,
                    this.config,
                    { width: window.innerWidth, height: window.innerHeight },
                );
            },
            closeRemoteMenu() { this.remoteMenu.open = false; },
            isFileVisible(fileId) {
                return this.visibleFileEntries.some(entry => entry.id === fileId);
            },
            pathDir(path) {
                if (!path) return '';
                const i = path.lastIndexOf('/');
                return i === -1 ? '' : path.slice(0, i + 1);
            },
            pathBase(path) {
                if (!path) return '';
                const i = path.lastIndexOf('/');
                return i === -1 ? path : path.slice(i + 1);
            },
            get visibleFileCount() {
                return this.visibleFileEntries.length;
            },
            buildFullPath(path) {
                const repo = this.repoPath || '';
                if (!repo) return path;
                return repo.replace(/\/+$/, '') + '/' + path;
            },
            scrollToFile(id, persist = true) {
                this.activeFile = id;
                // Persist the selection server-side so a later full parent re-render
                // re-seeds the highlight. Skippable when the caller already persisted
                // it (e.g. revealFile) to avoid a redundant round-trip.
                if (persist) {
                    this.$wire.selectFile(id);
                }
                this.$dispatch('expand-file', { id });
                document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            },
            focusAdjacentFile(delta) {
                // Move the selection between visible files (j/k). Computed client-side
                // off the visible list so rapid presses stay instant; selection clamps
                // at the ends rather than wrapping.
                const entries = this.visibleFileEntries;
                if (entries.length === 0) {
                    return;
                }
                const current = entries.findIndex(file => file.id === this.activeFile);
                const target = current === -1
                    ? (delta > 0 ? 0 : entries.length - 1)
                    : Math.min(entries.length - 1, Math.max(0, current + delta));
                this.scrollToFile(entries[target].id);
            },
            async scrollToComment(commentId, filePath) {
                const file = this.sourceFileEntries.find(f => f.path === filePath);
                if (!file) {
                    Flux.toast({ text: 'Comment is on a file not in this diff', variant: 'warning' });
                    return;
                }
                const revealed = !this.isFileVisible(file.id);
                if (revealed) {
                    await this.$wire.revealFile(file.id);
                }
                this.activeFile = file.id;
                (window.__rfaPendingExpandFiles ??= new Set()).add(file.id);
                // revealFile already set activeFileId server-side, so don't re-persist.
                this.scrollToFile(file.id, !revealed);
                clearTimeout(this.commentScrollPollId);
                const target = 'comment-' + commentId;
                const start = performance.now();
                const tryScroll = () => {
                    if (!this.$el?.isConnected) return;
                    // Re-dispatch every tick: the diff-file may be lazy and hydrate after
                    // the first dispatch, in which case its listeners weren't yet
                    // registered to receive the initial expand-file.
                    this.$dispatch('expand-file', { id: file.id });
                    this.$dispatch('unfold-for-comment', { fileId: file.id });
                    const el = document.getElementById(target);
                    if (el && el.offsetParent !== null) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                    }
                    if (performance.now() - start < 4000) {
                        this.commentScrollPollId = setTimeout(tryScroll, 100);
                    }
                };
                tryScroll();
            },
            destroy() {
                this.pendingSavesGuard?.detach();
                this.pendingSavesGuard = null;
                clearTimeout(this.commentScrollPollId);
            },
        };
    }

    function createChangePoller(config = {}) {
        return {
            hasChanges: false,
            fingerprint: null,
            currentCount: 0,
            stopPoll: null,
            async check() {
                try {
                    const res = await fetch('/api/changes/' + config.projectId);
                    const data = await res.json();
                    if (this.fingerprint === null) {
                        this.fingerprint = data.fingerprint;
                    } else if (data.fingerprint !== this.fingerprint) {
                        const newCount = data.count ?? 0;
                        if (!this.hasChanges || this.currentCount !== newCount) {
                            this.hasChanges = true;
                            this.currentCount = newCount;
                        }
                    }
                } catch {}
            },
            // Re-baseline after a refresh applied the pending changes. Bound to the
            // page's fingerprint-reset event.
            reset() {
                this.fingerprint = null;
                this.hasChanges = false;
                this.currentCount = 0;
                this.check();
            },
            softRefresh() { this.$wire.softRefresh(); },
            hardReload() { window.location.reload(); },
            get tooltip() {
                if (!this.hasChanges) return 'Refresh · ⌘R · ⌘⇧R to hard reload';
                const n = this.currentCount;
                const noun = n === 1 ? 'file' : 'files';
                return `${n} ${noun} changed externally - click to refresh`;
            },
            init() {
                this.check();
                this.stopPoll = window.smartPoll.startSmartPoll({
                    window,
                    document,
                    getInterval: () => window.smartPoll.isFocused(document) ? 60000 : (document.hidden ? null : 300000),
                    onTick: () => this.check(),
                });
                // Browser build only: the native build routes ⌘R through its menu.
                if (config.keymapEnabled) {
                    this.$store.keymap.register('⌘R', () => this.softRefresh(), { allowInEditable: true });
                    this.$store.keymap.register('⌘⇧R', () => this.hardReload(), { allowInEditable: true });
                }
            },
            destroy() {
                if (this.stopPoll) this.stopPoll();
                // Drop the shortcut bindings on teardown so a poller that's gone
                // (e.g. after navigating away) doesn't leave ⌘R pointing at a
                // dead component. The keymap store is keyed by combo, so this
                // also keeps a remount from re-binding a stale handler.
                if (config.keymapEnabled) {
                    this.$store.keymap.unregister('⌘R');
                    this.$store.keymap.unregister('⌘⇧R');
                }
            },
        };
    }

    function install(root) {
        if (typeof root.Alpine === 'undefined' || root.__reviewPageAttached) return false;
        root.__reviewPageAttached = true;
        root.Alpine.data('reviewPage', createReviewPage);
        root.Alpine.data('reviewChangePoller', createChangePoller);
        return true;
    }

    function autoInstall(root) {
        if (root.Alpine) {
            install(root);
        } else {
            root.document.addEventListener('alpine:init', () => install(root));
        }
    }

    return { computeRemoteMenu, createReviewPage, createChangePoller, install, autoInstall };
});

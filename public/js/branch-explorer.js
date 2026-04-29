// Alpine component for livewire/⚡branch-explorer.blade.php
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.branchExplorer = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    /**
     * @param {object} input
     * @param {Array<{hash: string}>} input.commits  newest-first; index 0 = tip.
     * @param {string[]} input.selectedHashes        subset of commits[].hash, any order.
     * @param {boolean}  input.workingTreeSelected
     * @param {string}   input.projectSlug
     * @returns {{kind: 'noop'} | {kind: 'navigate', url: string} | {kind: 'alert', message: string}}
     */
    function decideSelection({ commits, selectedHashes, workingTreeSelected, projectSlug }) {
        const hasAnySelection = workingTreeSelected || selectedHashes.length > 0;
        if (!hasAnySelection) return { kind: 'noop' };

        const indices = [...new Set(
            selectedHashes
                .map(h => commits.findIndex(c => c.hash === h))
                .filter(i => i >= 0)
        )].sort((a, b) => a - b);

        // Working tree alone → plain working-tree view.
        if (workingTreeSelected && indices.length === 0) {
            return { kind: 'navigate', url: `/p/${projectSlug}` };
        }

        // All hashes were unknown to `commits` — treat as nothing real selected.
        // Without this guard, the single-commit branch below dereferences
        // `commits[undefined].hash` and throws.
        if (indices.length === 0) return { kind: 'noop' };

        // A non-contiguous commit pick (e.g. A and C without B) would silently pull B
        // into the diff if we just used min/max. Reject it so users have to
        // explicitly include every commit in their range.
        if (indices[indices.length - 1] - indices[0] + 1 !== indices.length) {
            return {
                kind: 'alert',
                message: 'Selection is not contiguous — pick every commit between the oldest and newest you want to review.',
            };
        }

        // Commits are listed newest-first; lowest index = tip, highest = oldest.
        // Working tree is conceptually at index -1 (one step newer than the tip).
        // When WT is selected alongside commits, the commits must start at index 0.
        if (workingTreeSelected && indices.length > 0 && indices[0] !== 0) {
            return {
                kind: 'alert',
                message: 'Selection is not contiguous — working tree must be paired with the newest commits.',
            };
        }

        if (workingTreeSelected) {
            const oldest = commits[indices[indices.length - 1]];
            const fromRef = encodeURIComponent(oldest.hash + '^');
            return { kind: 'navigate', url: `/p/${projectSlug}/rw/${fromRef}` };
        }

        if (indices.length === 1) {
            return { kind: 'navigate', url: `/p/${projectSlug}/c/${commits[indices[0]].hash}` };
        }

        const newest = commits[indices[0]];
        const oldest = commits[indices[indices.length - 1]];
        const baseRef = encodeURIComponent(oldest.hash + '^');
        return { kind: 'navigate', url: `/p/${projectSlug}/${newest.hash}/${baseRef}` };
    }

    /**
     * Returns true when the current selection state is exactly "everything in base..HEAD
     * plus working tree" — i.e., the user clicked the "Since {base}" row and hasn't trimmed.
     *
     * @param {object} input
     * @param {string[]} input.selectedHashes
     * @param {boolean}  input.workingTreeSelected
     * @param {string[]} input.hashesInRange
     */
    function isSinceBaseExactly({ selectedHashes, workingTreeSelected, hashesInRange }) {
        if (!workingTreeSelected) return false;
        if (selectedHashes.length !== hashesInRange.length) return false;
        const set = new Set(hashesInRange);
        return selectedHashes.every(h => set.has(h));
    }

    function createBranchExplorer({ currentBranch, activeCommitHash, activeDiffFrom, projectSlug, branches }) {
        return {
            open: false,
            search: '',
            selectedIndex: 0,
            selectedBranch: currentBranch,
            allBranches: branches,
            activeCommitHash,
            activeDiffFrom: activeDiffFrom || 'HEAD',
            projectSlug,
            selectedHashes: [],
            workingTreeSelected: false,
            lastSelectionIndex: -1,
            _loadId: 0, // Stale-response guard: incremented before each async load, checked after

            get isWorkingTreeActive() {
                return this.activeCommitHash === null;
            },

            get hasAnySelection() {
                return this.workingTreeSelected || this.selectedHashes.length > 0;
            },

            /**
             * Showing the "Since {base}" row only makes sense for the project's
             * current branch — the configured base is HEAD-relative, not relative
             * to whichever branch the picker happens to be displaying. The
             * `on_base_branch` state is also hidden because comparing a branch
             * to itself is nonsense.
             */
            get sinceBaseRowVisible() {
                if (this.selectedBranch !== currentBranch) return false;
                const base = this.$wire.branchBase;
                if (!base) return false;
                return base.state !== 'on_base_branch';
            },

            get sinceBaseSelected() {
                const base = this.$wire.branchBase;
                if (!base || base.state !== 'ready') return false;
                return isSinceBaseExactly({
                    selectedHashes: this.selectedHashes,
                    workingTreeSelected: this.workingTreeSelected,
                    hashesInRange: base.hashesInRange,
                });
            },

            get selectionBadge() {
                const n = this.selectedHashes.length;
                if (this.workingTreeSelected && n > 0) return `WT+${n}`;
                if (this.workingTreeSelected) return 'WT';
                return String(n);
            },

            get selectionDescription() {
                const parts = [];
                if (this.workingTreeSelected) parts.push('working tree');
                if (this.selectedHashes.length > 0) {
                    parts.push(`${this.selectedHashes.length} commit${this.selectedHashes.length === 1 ? '' : 's'}`);
                }
                return parts.join(' + ') || 'nothing';
            },

            _filterBranches(key) {
                const list = this.allBranches[key] || [];
                if (this.search === '') return list;
                const q = this.search.toLowerCase();
                return list.filter(b => b.name.toLowerCase().includes(q));
            },

            get filteredLocal() { return this._filterBranches('local'); },
            get filteredRemote() { return this._filterBranches('remote'); },
            get allFiltered() { return [...this.filteredLocal, ...this.filteredRemote]; },

            async openPanel() {
                this.open = true;
                this.search = '';
                this.selectedIndex = 0;
                this.clearSelection();
                Alpine.store('overlays').open('branch-explorer');
                // Resolve base info before loading commits so the commit-load
                // can extend its window to cover all base..HEAD hashes when the
                // configured base is more than `pageSize` commits behind.
                await Promise.all([
                    this.$wire.loadBranches(),
                    this.$wire.loadBranchBase(),
                ]);
                this.allBranches = this.$wire.branches;
                const currentIdx = this.allFiltered.findIndex(b => b.name === this.selectedBranch);
                if (currentIdx >= 0) this.selectedIndex = currentIdx;
                this.loadSelectedBranch();
                await this.$nextTick();
                this.$refs.searchInput?.focus();
            },

            closePanel() {
                this.open = false;
                if (Alpine.store('overlays').is('branch-explorer')) Alpine.store('overlays').close();
            },

            async loadSelectedBranch() {
                const branch = this.allFiltered[this.selectedIndex];
                if (!branch) return;
                if (branch.name === this.selectedBranch && this.$wire.commits.length > 0) return;
                // Selected branch is actually changing — stale hashes no longer apply.
                const branchChanged = this.selectedBranch !== branch.name;
                this.selectedBranch = branch.name;
                const id = ++this._loadId;
                await this.$wire.loadCommits(branch.name);
                if (this._loadId !== id) return;
                if (branchChanged) this.clearSelection();
            },

            handleKeydown(e) {
                if (!this.open) return;

                if (e.key === 'Escape') {
                    this.closePanel();
                    e.preventDefault();
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (this.selectedIndex < this.allFiltered.length - 1) {
                        this.selectedIndex++;
                        this.loadSelectedBranch();
                        this.scrollSelectedIntoView();
                    }
                    return;
                }

                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (this.selectedIndex > 0) {
                        this.selectedIndex--;
                        this.loadSelectedBranch();
                        this.scrollSelectedIntoView();
                    }
                    return;
                }
            },

            handleSearchEscape(e) {
                const hasFilter = e.target.value !== '' || this.search !== '';

                e.target.value = '';
                e.target.dispatchEvent(new Event('input', { bubbles: true }));
                e.target.blur();

                if (!hasFilter) return;

                this.search = '';
                this.onSearchChange();
            },

            onSearchChange() {
                this.selectedIndex = 0;
                this.loadSelectedBranch();
            },

            scrollSelectedIntoView() {
                this.$nextTick(() => {
                    this.$refs.branchList?.querySelector('[data-selected=true]')?.scrollIntoView({ block: 'nearest' });
                });
            },

            selectBranchAt(index) {
                this.selectedIndex = index;
                this.loadSelectedBranch();
            },

            copyHash(hash) {
                navigator.clipboard.writeText(hash).catch(() => {});
            },

            viewCommit(hash) {
                Livewire.navigate(`/p/${this.projectSlug}/c/${hash}`);
            },

            viewWorkingTree() {
                Livewire.navigate(`/p/${this.projectSlug}`);
            },

            isSelected(hash) {
                return this.selectedHashes.includes(hash);
            },

            toggleSelection(hash, idx, event) {
                event.stopPropagation();

                if (event.shiftKey && this.lastSelectionIndex >= 0) {
                    const start = Math.min(this.lastSelectionIndex, idx);
                    const end = Math.max(this.lastSelectionIndex, idx);
                    const rangeHashes = this.$wire.commits.slice(start, end + 1).map(c => c.hash);
                    const merged = new Set(this.selectedHashes);
                    rangeHashes.forEach(h => merged.add(h));
                    this.selectedHashes = [...merged];
                    return;
                }

                const i = this.selectedHashes.indexOf(hash);
                if (i >= 0) {
                    this.selectedHashes.splice(i, 1);
                } else {
                    this.selectedHashes.push(hash);
                }
                this.lastSelectionIndex = idx;
            },

            toggleWorkingTreeSelection(event) {
                event.stopPropagation();

                // Shift-click with a prior commit selection extends the range from the
                // working tree down through commit index [0..lastSelectionIndex].
                if (event.shiftKey && this.lastSelectionIndex >= 0) {
                    const rangeHashes = this.$wire.commits.slice(0, this.lastSelectionIndex + 1).map(c => c.hash);
                    const merged = new Set(this.selectedHashes);
                    rangeHashes.forEach(h => merged.add(h));
                    this.selectedHashes = [...merged];
                    this.workingTreeSelected = true;
                    return;
                }

                this.workingTreeSelected = !this.workingTreeSelected;
            },

            clearSelection() {
                this.selectedHashes = [];
                this.workingTreeSelected = false;
                this.lastSelectionIndex = -1;
            },

            /**
             * Click handler for the "Since {base}" row. Fills the multi-select
             * with every commit in `base..HEAD` plus working tree, so the user
             * sees scope visually and can trim before pressing Apply. Toggles
             * off when invoked while the exact since-base shape is already
             * selected.
             */
            selectSinceBase() {
                const base = this.$wire.branchBase;
                if (!base || base.state !== 'ready') return;

                if (this.sinceBaseSelected) {
                    this.clearSelection();
                    return;
                }

                this.selectedHashes = [...base.hashesInRange];
                this.workingTreeSelected = true;
                // Reset shift-click anchor so a subsequent shift-click on a
                // commit doesn't extend from a stale index.
                this.lastSelectionIndex = -1;
            },

            // Press-and-hold on a commit's checkbox, then drag across rows to extend
            // the selection from the anchor. Mirrors the diff-file line-range gesture
            // so the app has one "mouse path selects a range" pattern, not two.
            startDrag(idx, event) {
                if (event.button !== 0) return;
                if (event.shiftKey) return;
                let moved = false;
                let active = true;
                let lastHoveredIdx = idx;

                const onPointerOver = (e) => {
                    if (!active) return;
                    if (e.buttons === 0) {
                        // Mouse released outside the window — recover on first re-entry.
                        // Release wasn't in-window, so there's no trailing click to swallow.
                        endDrag(false);
                        return;
                    }
                    const row = e.target?.closest?.('[data-commit-idx]');
                    if (!row) return;
                    const hovered = parseInt(row.dataset.commitIdx, 10);
                    if (Number.isNaN(hovered)) return;
                    if (hovered === lastHoveredIdx) return;
                    lastHoveredIdx = hovered;
                    moved = true;
                    const start = Math.min(idx, hovered);
                    const end = Math.max(idx, hovered);
                    this.selectedHashes = this.$wire.commits.slice(start, end + 1).map(c => c.hash);
                };

                const endDrag = (swallowClick) => {
                    if (!active) return;
                    active = false;
                    window.removeEventListener('pointerover', onPointerOver);
                    window.removeEventListener('pointerup', onPointerUp);
                    window.removeEventListener('blur', onBlur);
                    if (!moved) return;
                    this.lastSelectionIndex = idx;
                    if (!swallowClick) return;
                    // Whichever element mouseup landed on, its click fires next.
                    // Swallow it so we don't navigate into a commit or re-toggle.
                    const swallow = (ev) => {
                        ev.stopPropagation();
                        ev.preventDefault();
                        window.removeEventListener('click', swallow, true);
                    };
                    window.addEventListener('click', swallow, true);
                };

                const onPointerUp = () => endDrag(true);
                const onBlur = () => endDrag(false);

                window.addEventListener('pointerover', onPointerOver);
                window.addEventListener('pointerup', onPointerUp);
                window.addEventListener('blur', onBlur);
            },

            applySelection() {
                const result = decideSelection({
                    commits: this.$wire.commits,
                    selectedHashes: this.selectedHashes,
                    workingTreeSelected: this.workingTreeSelected,
                    projectSlug: this.projectSlug,
                });

                if (result.kind === 'noop') return;
                if (result.kind === 'alert') {
                    window.alert(result.message);
                    return;
                }
                if (result.kind === 'navigate') {
                    Livewire.navigate(result.url);
                    this.closePanel();
                }
            },
        };
    }

    function install(root) {
        if (typeof root.Alpine === 'undefined' || root.__branchExplorerAttached) return false;
        root.__branchExplorerAttached = true;
        root.Alpine.data('branchExplorer', createBranchExplorer);
        return true;
    }

    function autoInstall(root) {
        if (root.Alpine) {
            install(root);
        } else {
            root.document.addEventListener('alpine:init', () => install(root));
        }
    }

    return { decideSelection, isSinceBaseExactly, createBranchExplorer, install, autoInstall };
});

// Alpine component for livewire/⚡branch-explorer.blade.php
(function () {
    function init() {
        Alpine.data('branchExplorer', ({ currentBranch, activeCommitHash, projectSlug, branches }) => ({
            open: false,
            search: '',
            selectedIndex: 0,
            selectedBranch: currentBranch,
            allBranches: branches,
            activeCommitHash,
            projectSlug,
            selectedHashes: [],
            lastSelectionIndex: -1,
            _loadId: 0, // Stale-response guard: incremented before each async load, checked after

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
                await this.$wire.loadBranches();
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

            clearSelection() {
                this.selectedHashes = [];
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
                        endDrag();
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

                const endDrag = () => {
                    if (!active) return;
                    active = false;
                    window.removeEventListener('pointerover', onPointerOver);
                    window.removeEventListener('pointerup', endDrag);
                    window.removeEventListener('blur', endDrag);
                    if (moved) {
                        // Whichever element mouseup landed on, its click fires next.
                        // Swallow it so we don't navigate into a commit or re-toggle.
                        const swallow = (ev) => {
                            ev.stopPropagation();
                            ev.preventDefault();
                            window.removeEventListener('click', swallow, true);
                        };
                        window.addEventListener('click', swallow, true);
                        this.lastSelectionIndex = idx;
                    }
                };

                window.addEventListener('pointerover', onPointerOver);
                window.addEventListener('pointerup', endDrag);
                window.addEventListener('blur', endDrag);
            },

            applySelection() {
                if (this.selectedHashes.length === 0) return;

                const indices = [...new Set(
                    this.selectedHashes
                        .map(h => this.$wire.commits.findIndex(c => c.hash === h))
                        .filter(i => i >= 0)
                )].sort((a, b) => a - b);

                if (indices.length === 0) return;

                if (indices.length === 1) {
                    Livewire.navigate(`/p/${this.projectSlug}/c/${this.$wire.commits[indices[0]].hash}`);
                    this.closePanel();
                    return;
                }

                // A non-contiguous pick (e.g. A and C without B) would silently pull B
                // into the diff if we just used min/max. Reject it so users have to
                // explicitly include every commit in their range.
                if (indices[indices.length - 1] - indices[0] + 1 !== indices.length) {
                    window.alert('Selection is not contiguous — pick every commit between the oldest and newest you want to review.');
                    return;
                }

                // Commits are listed newest-first; lowest index = tip, highest = oldest.
                const newest = this.$wire.commits[indices[0]];
                const oldest = this.$wire.commits[indices[indices.length - 1]];
                const baseRef = encodeURIComponent(oldest.hash + '^');
                Livewire.navigate(`/p/${this.projectSlug}/${newest.hash}/${baseRef}`);
                this.closePanel();
            },
        }));
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

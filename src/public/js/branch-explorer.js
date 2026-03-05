// Alpine component for livewire/⚡branch-explorer.blade.php
(function () {
    function init() {
        Alpine.data('branchExplorer', ({ currentBranch, activeCommitHash, projectSlug, branches }) => ({
            open: false,
            search: '',
            selectedIndex: 0,
            selectedBranch: currentBranch,
            allBranches: branches,
            baseHash: null,
            baseShortHash: null,
            activeCommitHash,
            projectSlug,
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
            },

            async loadSelectedBranch() {
                const branch = this.allFiltered[this.selectedIndex];
                if (!branch) return;
                if (branch.name === this.selectedBranch && this.$wire.commits.length > 0) return;
                this.selectedBranch = branch.name;
                const id = ++this._loadId;
                await this.$wire.loadCommits(branch.name);
                if (this._loadId !== id) return;
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
                if (this.baseHash) {
                    Livewire.navigate(`/p/${this.projectSlug}/${hash}/${this.baseHash}`);
                } else {
                    Livewire.navigate(`/p/${this.projectSlug}/c/${hash}`);
                }
            },

            setBase(hash, short) {
                this.baseHash = hash;
                this.baseShortHash = short;
            },

            clearBase() {
                this.baseHash = null;
                this.baseShortHash = null;
            },

            viewWorkingTree() {
                Livewire.navigate(`/p/${this.projectSlug}`);
            },
        }));
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

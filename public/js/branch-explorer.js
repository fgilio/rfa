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
    // Mirrors App\Enums\BranchBaseState. Keep values in sync with the PHP enum.
    const BranchBaseState = Object.freeze({
        Ready: 'ready',
        NotConfigured: 'not_configured',
        UpToDate: 'up_to_date',
        MissingRef: 'missing_ref',
        OnBaseBranch: 'on_base_branch',
    });

    /**
     * Returns true when the current selection state is exactly "everything in base..HEAD
     * plus working tree" - i.e., the user clicked the "Since {base}" row and hasn't trimmed.
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

    /**
     * Working tree extends a diff through HEAD's working state, so a selection
     * that pairs WT with commits MUST include the tip - otherwise the user is
     * asking for a diff that excludes HEAD's commit while still including its
     * working tree, which has no coherent diff range.
     *
     * @param {object} input
     * @param {string[]} input.selectedHashes
     * @param {boolean}  input.workingTreeSelected
     * @param {Array<{hash: string}>} input.commits  newest-first; index 0 = tip.
     */
    function violatesTipAnchor({ selectedHashes, workingTreeSelected, commits }) {
        if (!workingTreeSelected) return false;
        if (selectedHashes.length === 0) return false;
        const tip = commits[0];
        if (!tip) return false;
        return !selectedHashes.includes(tip.hash);
    }

    const EMPTY_TREE_HASH = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    function splitParentRef(ref) {
        const value = String(ref || '');
        const isParentRef = value.endsWith('^');

        return {
            value: isParentRef ? value.slice(0, -1) : value,
            isParentRef,
        };
    }

    function commitIndexByRef(commits, ref) {
        const { value } = splitParentRef(ref);
        if (!value) return -1;

        const exact = commits.findIndex(c => c.hash === value);
        if (exact >= 0) return exact;

        const matches = commits
            .map((commit, index) => commit.hash.startsWith(value) ? index : -1)
            .filter(index => index >= 0);

        return matches.length === 1 ? matches[0] : -1;
    }

    /**
     * Strip a remote-tracking branch's `<remote>/` prefix for display. The full
     * ref (e.g. `origin/feature/x`) is what git needs to resolve the branch, but
     * under the picker's "Remote" header the remote is implied, so the list shows
     * just `feature/x` - keeping the distinguishing tail visible in a narrow pane.
     *
     * @param {string}  name    full branch name, e.g. `origin/feature/x`
     * @param {?string} remote  remote name, e.g. `origin`
     */
    function stripRemotePrefix(name, remote) {
        const value = String(name || '');
        if (remote && value.startsWith(remote + '/')) {
            return value.slice(remote.length + 1);
        }
        return value;
    }

    function createBranchExplorer({ currentBranch, activeCommitHash, activeDiffFrom, projectSlug, branches }) {
        return {
            open: false,
            search: '',
            selectedIndex: 0,
            currentBranch,
            selectedBranch: currentBranch,
            allBranches: branches,
            activeCommitHash,
            activeDiffFrom: activeDiffFrom || 'HEAD',
            projectSlug,
            selectionError: '',
            selectedHashes: [],
            workingTreeSelected: false,
            // Shift-click anchor. At most one is active at a time:
            // `lastSelectionIndex >= 0` -> commit at that index; or
            // `lastSelectionAnchorIsWT` -> the working-tree row. Both can be
            // inactive (after init, `clearSelection`, `selectSinceBase`, or
            // commit-view rehydrate); never both active. Every non-shift click
            // resets whichever isn't being set.
            lastSelectionIndex: -1,
            lastSelectionAnchorIsWT: false,
            _loadId: 0, // Stale-response guard: incremented before each async load, checked after

            get isWorkingTreeActive() {
                return this.activeCommitHash === null && this.selectedBranch === this.currentBranch;
            },

            get hasAnySelection() {
                return this.workingTreeSelected || this.selectedHashes.length > 0;
            },

            get workingTreeSelectable() {
                return this.selectedBranch === this.currentBranch;
            },

            /**
             * The "Since {base}" row is always rendered for a predictable layout,
             * but it's only clickable on the project's current branch with a base
             * that's configured, resolved, and ahead of HEAD. The configured base
             * is HEAD-relative, so it's meaningless while browsing another branch.
             */
            get sinceBaseActionable() {
                const base = this.$wire.branchBase;
                if (!base) return false;
                if (this.selectedBranch !== this.currentBranch) return false;
                return base.state === BranchBaseState.Ready;
            },

            /**
             * One-line explanation under the "Since {base}" row. For the ready
             * state it's the scope summary; otherwise it names why the row can't
             * be used right now. Null-safe: the row renders before branchBase
             * loads, so a missing snapshot must not throw.
             */
            get sinceBaseReason() {
                const base = this.$wire.branchBase;
                if (!base) return '';
                if (this.selectedBranch !== this.currentBranch) {
                    return `compares against your current branch (${this.currentBranch})`;
                }

                switch (base.state) {
                    case BranchBaseState.Ready:
                        return `${base.commitCount} commit${base.commitCount === 1 ? '' : 's'} + uncommitted changes`;
                    case BranchBaseState.UpToDate:
                        return 'no commits ahead';
                    case BranchBaseState.MissingRef:
                        return 'base ref not found locally (run git fetch)';
                    case BranchBaseState.OnBaseBranch:
                        return "you're on the base branch";
                    case BranchBaseState.NotConfigured:
                        return 'set a base branch in project settings';
                    default:
                        return '';
                }
            },

            /**
             * True when the active view is the whole repo ("Since the beginning").
             * The empty-tree check alone is ambiguous: a root commit's diff also
             * starts from the empty tree, so require `activeCommitHash === null`
             * (working-tree target) to tell the two apart.
             */
            get sinceBeginningActive() {
                return this.activeCommitHash === null && this.activeDiffFrom === EMPTY_TREE_HASH;
            },

            /**
             * True when the active view IS the since-base range - working-tree
             * target diffed from the configured base sha. Lights the row up as
             * the current view (like an active commit row), distinct from
             * `sinceBaseSelected`, which tracks an in-progress, not-yet-applied
             * selection. Only meaningful on the current branch, where
             * activeDiffFrom is HEAD-relative.
             */
            get sinceBaseActive() {
                const base = this.$wire.branchBase;
                if (!base || base.state !== BranchBaseState.Ready || !base.baseSha) return false;
                if (this.selectedBranch !== this.currentBranch) return false;
                return this.activeCommitHash === null && this.activeDiffFrom === base.baseSha;
            },

            get sinceBaseSelected() {
                const base = this.$wire.branchBase;
                if (!base || base.state !== BranchBaseState.Ready) return false;
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

            get loadedCommitsSummary() {
                const n = this.$wire.commits?.length || 0;
                const noun = (n === 1 && !this.$wire.hasMore) ? 'commit' : 'commits';
                return `${n}${this.$wire.hasMore ? '+' : ''} ${noun}`;
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

            /** Display label for a remote branch row - the `<remote>/` prefix dropped. */
            remoteBranchLabel(branch) {
                return stripRemotePrefix(branch.name, branch.remote);
            },

            /**
             * True when the repo tracks more than one remote (e.g. origin +
             * upstream). With a single remote the dropped `<remote>/` prefix is
             * pure noise; with several, a muted tag keeps `origin/x` distinct
             * from `upstream/x`.
             */
            get hasMultipleRemotes() {
                const remotes = new Set((this.allBranches.remote || []).map(b => b.remote).filter(Boolean));
                return remotes.size > 1;
            },

            async openPanel() {
                this.open = true;
                this.search = '';
                this.selectedIndex = 0;
                this._clearSelectionError();
                Alpine.store('overlays').open('branch-explorer');

                await this.refreshSnapshot(this.selectedBranch || this.currentBranch, { force: true });

                const currentIdx = this.allFiltered.findIndex(b => b.name === this.selectedBranch);
                if (currentIdx >= 0) this.selectedIndex = currentIdx;
                this._rehydrateSelectionFromActiveView();
                await this.$nextTick();
                this._scrollActiveCommitIntoView();
                this.$refs.searchInput?.focus();
            },

            closePanel() {
                this.open = false;
                if (Alpine.store('overlays').is('branch-explorer')) Alpine.store('overlays').close();
            },

            async refreshSnapshot(branchName, { clear = false, force = false, minimumCommitCount = 0 } = {}) {
                if (!force && branchName === this.selectedBranch && this.$wire.commits.length > 0) return true;

                const id = ++this._loadId;
                await this.$wire.loadSnapshot(branchName, minimumCommitCount);
                if (this._loadId !== id) return false;

                this.allBranches = this.$wire.branches;
                this.selectedBranch = this.$wire.snapshotBranch || branchName;

                if (clear) this.clearSelection();

                return true;
            },

            async loadSelectedBranch({ force = false } = {}) {
                const branch = this.allFiltered[this.selectedIndex];
                if (!branch) return;
                // Selected branch is actually changing - stale hashes no longer apply.
                const branchChanged = this.selectedBranch !== branch.name;
                this._clearSelectionError();
                await this.refreshSnapshot(branch.name, { clear: branchChanged, force });
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
                this.loadSelectedBranch({ force: true });
            },

            copyHash(hash) {
                this.$dispatch('copy-to-clipboard', { text: hash, toast: 'Copied ' + hash.slice(0, 7) });
            },

            openRemoteContext(event, type, params, label) {
                this.$dispatch('open-remote-menu', {
                    target: 'direct',
                    type,
                    params,
                    label,
                    projectSlug: this.projectSlug,
                    clientX: event.clientX,
                    clientY: event.clientY,
                });
            },

            handleRemoteContextMenu(event) {
                const trigger = event.target.closest('[data-remote-context]');

                if (!trigger) return;

                const type = trigger.dataset.remoteType;

                if (!type) return;

                let params = {};
                try {
                    params = JSON.parse(trigger.dataset.remoteParams || '{}');
                } catch {
                    params = {};
                }

                event.preventDefault();
                this.openRemoteContext(event, type, params, trigger.dataset.remoteLabel || 'on remote');
            },

            openSelectionRemoteContext(event) {
                if (this.activeCommitHash) {
                    this.openRemoteContext(event, 'commit', { sha: this.activeCommitHash }, `commit ${this.activeCommitHash.slice(0, 7)}`);
                    return;
                }

                this.openRemoteContext(event, 'branch', { name: this.currentBranch }, `branch ${this.currentBranch}`);
            },

            viewCommit(hash) {
                Livewire.navigate(`/p/${this.projectSlug}/c/${hash}`);
            },

            viewWorkingTree() {
                Livewire.navigate(`/p/${this.projectSlug}`);
            },

            viewSinceBeginning() {
                Livewire.navigate(`/p/${this.projectSlug}/rw/${EMPTY_TREE_HASH}`);
            },

            /**
             * Click handler for the "Since {base}" row body. Auto-applies the
             * whole base..HEAD + working tree range as one diff - the one-click
             * counterpart to the row's seed-and-trim checkbox ({@see
             * selectSinceBase}).
             *
             * Unlike a commit row (immutable sha) or "Since the beginning"
             * (constant empty tree), the since-base endpoint is a *resolved*
             * merge-base that can move if the repo advances while the drawer is
             * open. So this routes through the same server `applySelection`
             * flow as Apply rather than a raw client navigate: that re-reads
             * git, revalidates `snapshotKey`, and recomputes a fresh base sha -
             * falling back to a stale-refresh toast instead of stranding the
             * user on an outdated diff.
             */
            async viewSinceBase() {
                if (!this.sinceBaseActionable) return;
                const base = this.$wire.branchBase;

                // Force the exact since-base shape (not selectSinceBase, which
                // toggles off when it's already selected) and apply it.
                this._clearSelectionError();
                this.selectedHashes = [...base.hashesInRange];
                this.workingTreeSelected = true;
                this.lastSelectionIndex = -1;
                this.lastSelectionAnchorIsWT = false;

                await this.applySelection();
            },

            isSelected(hash) {
                return this.selectedHashes.includes(hash);
            },

            isActiveCommit(hash) {
                const activeIndex = commitIndexByRef(this.$wire.commits || [], this.activeCommitHash);

                return activeIndex >= 0 && this.$wire.commits[activeIndex]?.hash === hash;
            },

            toggleSelection(hash, idx, event) {
                event.stopPropagation();
                this._clearSelectionError();

                if (event.shiftKey) {
                    if (this.lastSelectionAnchorIsWT) {
                        this._mergeRangeIntoSelection(0, idx);
                        this.workingTreeSelected = true;
                        return;
                    }

                    if (this.lastSelectionIndex >= 0) {
                        this._mergeRangeIntoSelection(
                            Math.min(this.lastSelectionIndex, idx),
                            Math.max(this.lastSelectionIndex, idx),
                        );
                        return;
                    }
                }

                const i = this.selectedHashes.indexOf(hash);
                if (i >= 0) {
                    this.selectedHashes.splice(i, 1);
                } else {
                    this.selectedHashes.push(hash);
                }
                this.lastSelectionIndex = idx;
                this.lastSelectionAnchorIsWT = false;
                this._enforceWorkingTreeTipAnchor();
            },

            toggleWorkingTreeSelection(event) {
                event.stopPropagation();
                this._clearSelectionError();

                if (!this.workingTreeSelectable) {
                    this.showSelectionError('Working tree can only be paired with commits from the current branch.');
                    return;
                }

                if (event.shiftKey && this.lastSelectionIndex >= 0 && !this.lastSelectionAnchorIsWT) {
                    this._mergeRangeIntoSelection(0, this.lastSelectionIndex);
                    this.workingTreeSelected = true;
                    return;
                }

                this.workingTreeSelected = !this.workingTreeSelected;
                // Single-click on WT seeds it as the shift anchor regardless
                // of the resulting tick state, mirroring commit-side toggle.
                this.lastSelectionAnchorIsWT = true;
                this.lastSelectionIndex = -1;
            },

            /** Merge `commits[startIdx..endIdx]` (inclusive) hashes into `selectedHashes`. */
            _mergeRangeIntoSelection(startIdx, endIdx) {
                const commits = this.$wire.commits;
                const merged = new Set(this.selectedHashes);
                for (let i = startIdx; i <= endIdx; i++) {
                    merged.add(commits[i].hash);
                }
                this.selectedHashes = [...merged];
            },

            clearSelection() {
                this._clearSelectionError();
                this.selectedHashes = [];
                this.workingTreeSelected = false;
                this.lastSelectionIndex = -1;
                this.lastSelectionAnchorIsWT = false;
            },

            showSelectionError(message) {
                this.selectionError = message || 'Unable to apply selection.';
            },

            handleSnapshotStale(message) {
                this.showSelectionError(message);
                this.selectedHashes = [];
                this.workingTreeSelected = false;
                this.lastSelectionIndex = -1;
                this.lastSelectionAnchorIsWT = false;
                this._rehydrateSelectionFromActiveView();
            },

            _clearSelectionError() {
                this.selectionError = '';
            },

            /**
             * Mirror the page's current diff target into the picker's
             * multi-select shape. Only meaningful on the current branch -
             * activeDiffFrom/activeCommitHash are HEAD-relative.
             */
            _rehydrateSelectionFromActiveView() {
                if (this.selectedBranch !== this.currentBranch) return;

                const from = this.activeDiffFrom;
                const tip = this.activeCommitHash;

                // "Since the beginning" is a fixed whole-repo view, not a commit
                // range. Leave the multi-select empty so Apply stays a no-op until
                // the user makes a deliberate selection - otherwise rehydrating it
                // as "all loaded commits + WT" would let Apply rewrite the mode.
                if (this.sinceBeginningActive) {
                    this.workingTreeSelected = false;
                    this.selectedHashes = [];
                    this.lastSelectionAnchorIsWT = false;
                    this.lastSelectionIndex = -1;
                    return;
                }

                if (tip === null && from === 'HEAD') {
                    this.workingTreeSelected = true;
                    this.selectedHashes = [];
                    this.lastSelectionAnchorIsWT = true;
                    this.lastSelectionIndex = -1;
                    return;
                }

                if (tip === null) {
                    this.workingTreeSelected = true;
                    const base = this.$wire.branchBase;
                    this.selectedHashes = (base?.state === BranchBaseState.Ready && base.baseSha === from)
                        ? [...base.hashesInRange]
                        : this._hashesInRange(from, null);
                    this.lastSelectionAnchorIsWT = true;
                    this.lastSelectionIndex = -1;
                    return;
                }

                this.workingTreeSelected = false;
                const slice = this._hashesInRange(from, tip);
                this.selectedHashes = slice.length ? slice : [tip];
                this.lastSelectionAnchorIsWT = false;
                this.lastSelectionIndex = -1;
            },

            /**
             * Scroll the commit row that anchors the current view into the
             * picker so reopening doesn't strand the user at the top. For
             * /c and /r the anchor is `activeCommitHash`; for /rw it's the
             * base sha, so the bottom of the "Since X" range is visible.
             */
            _scrollActiveCommitIntoView() {
                if (this.selectedBranch !== this.currentBranch) return;
                const anchor = this.activeCommitHash
                    ?? (this.activeDiffFrom !== 'HEAD' ? this.activeDiffFrom : null);
                if (anchor === null) return;
                const anchorIndex = commitIndexByRef(this.$wire.commits || [], anchor);
                const anchorHash = this.$wire.commits?.[anchorIndex]?.hash;
                if (!anchorHash) return;
                const row = this.$refs.commitList?.querySelector(`[data-commit-hash="${anchorHash}"]`);
                row?.scrollIntoView({ block: 'center' });
            },

            /** Loaded commits in `(fromRef, toRef]`, newest-first. `toRef === null` means HEAD (index 0). */
            _hashesInRange(fromRef, toRef) {
                const commits = this.$wire.commits || [];
                const tipIdx = toRef === null ? 0 : commitIndexByRef(commits, toRef);
                if (tipIdx < 0) return [];

                if (fromRef === EMPTY_TREE_HASH) {
                    return commits.slice(tipIdx).map(c => c.hash);
                }

                const from = splitParentRef(fromRef);
                const fromIdx = commitIndexByRef(commits, from.value);
                if (fromIdx < 0) return [];

                const endExclusive = from.isParentRef ? fromIdx + 1 : fromIdx;
                if (endExclusive <= tipIdx) return [];

                return commits.slice(tipIdx, endExclusive).map(c => c.hash);
            },

            /**
             * Auto-fix the tip-anchor invariant after the user's last action,
             * so e.g. unticking the tip while WT is selected silently unticks
             * WT (with a toast) instead of blocking on Apply. Quietly fixing
             * the state feels less hostile than rejecting the action.
             */
            _enforceWorkingTreeTipAnchor() {
                if (!violatesTipAnchor({
                    selectedHashes: this.selectedHashes,
                    workingTreeSelected: this.workingTreeSelected,
                    commits: this.$wire.commits,
                })) return;

                this.workingTreeSelected = false;
                if (typeof window !== 'undefined' && window.Flux?.toast) {
                    window.Flux.toast({
                        text: 'Working tree removed - it includes HEAD\'s commit, so the tip must stay selected.',
                        variant: 'info',
                    });
                }
            },

            /**
             * Click handler for the "Since {base}" row's checkbox. Fills the
             * multi-select with every commit in `base..HEAD` plus working tree,
             * so the user sees scope visually and can trim before pressing
             * Apply. (The row body itself auto-applies via {@see viewSinceBase};
             * this checkbox is the seed-and-trim path.) Toggles off when invoked
             * while the exact since-base shape is already selected.
             */
            selectSinceBase() {
                if (!this.sinceBaseActionable) return;
                const base = this.$wire.branchBase;
                this._clearSelectionError();

                if (this.sinceBaseSelected) {
                    this.clearSelection();
                    return;
                }

                this.selectedHashes = [...base.hashesInRange];
                this.workingTreeSelected = true;
                // since-base is a bulk action, not a user click on WT - it
                // doesn't seed WT (or any commit) as the shift anchor.
                this.lastSelectionIndex = -1;
                this.lastSelectionAnchorIsWT = false;
            },

            // Press-and-hold on a commit's checkbox, then drag across rows to extend
            // the selection from the anchor. Mirrors the diff-file line-range gesture
            // so the app has one "mouse path selects a range" pattern, not two.
            startDrag(idx, event) {
                if (event.button !== 0) return;
                if (event.shiftKey) return;
                this._clearSelectionError();
                let moved = false;
                let active = true;
                let lastHoveredIdx = idx;

                const onPointerOver = (e) => {
                    if (!active) return;
                    if (e.buttons === 0) {
                        // Mouse released outside the window - recover on first re-entry.
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
                    this._enforceWorkingTreeTipAnchor();
                };

                const endDrag = (swallowClick) => {
                    if (!active) return;
                    active = false;
                    window.removeEventListener('pointerover', onPointerOver);
                    window.removeEventListener('pointerup', onPointerUp);
                    window.removeEventListener('blur', onBlur);
                    if (!moved) return;
                    this.lastSelectionIndex = idx;
                    this.lastSelectionAnchorIsWT = false;
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

            async loadMoreCommits() {
                this._clearSelectionError();
                const id = ++this._loadId;
                const before = this.$wire.snapshotKey || '';
                await this.$wire.loadMore(this.selectedBranch, before);
                if (this._loadId !== id) return false;
                this.selectedBranch = this.$wire.snapshotBranch || this.selectedBranch;
                this.allBranches = this.$wire.branches;
                return true;
            },

            async applySelection() {
                this._clearSelectionError();
                await this.$wire.applySelection(
                    this.selectedBranch,
                    this.selectedHashes,
                    this.workingTreeSelected,
                    this.$wire.snapshotKey || '',
                );
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

    return { BranchBaseState, EMPTY_TREE_HASH, isSinceBaseExactly, violatesTipAnchor, stripRemotePrefix, createBranchExplorer, install, autoInstall };
});

<?php

use App\Actions\GetBranchListAction;
use App\Actions\GetCommitHistoryAction;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $repoPath = '';

    #[Locked]
    public string $currentBranch = '';

    /** @var array{local: list<array<string, mixed>>, remote: list<array<string, mixed>>, current: string} */
    public array $branches = ['local' => [], 'remote' => [], 'current' => ''];

    /** @var list<array<string, mixed>> */
    public array $commits = [];

    public bool $hasMore = false;

    private int $pageSize = 50;

    public function loadBranches(): void
    {
        $this->branches = app(GetBranchListAction::class)->handle($this->repoPath);
    }

    public function loadCommits(string $branch): void
    {
        $commits = app(GetCommitHistoryAction::class)->handle($this->repoPath, $this->pageSize, 0, $branch);

        $this->commits = $commits;
        $this->hasMore = count($commits) >= $this->pageSize;
    }

    public function loadMore(string $branch): void
    {
        $offset = count($this->commits);
        $more = app(GetCommitHistoryAction::class)->handle($this->repoPath, $this->pageSize, $offset, $branch);

        $this->commits = array_merge($this->commits, $more);
        $this->hasMore = count($more) >= $this->pageSize;
    }
};

?>

<div
    x-data="{
        open: false,
        search: '',
        selectedIndex: 0,
        selectedBranch: @js($currentBranch),
        allBranches: @js($branches),
        localLabel: 'Local',
        remoteLabel: 'Remote',

        get filteredLocal() {
            if (this.search === '') return this.allBranches.local || [];
            const q = this.search.toLowerCase();
            return (this.allBranches.local || []).filter(b => b.name.toLowerCase().includes(q));
        },

        get filteredRemote() {
            if (this.search === '') return this.allBranches.remote || [];
            const q = this.search.toLowerCase();
            return (this.allBranches.remote || []).filter(b => b.name.toLowerCase().includes(q));
        },

        get allFiltered() {
            return [...this.filteredLocal, ...this.filteredRemote];
        },

        async openPanel() {
            this.open = true;
            this.search = '';
            this.selectedIndex = 0;
            await $wire.loadBranches();
            this.allBranches = $wire.branches;
            // Select current branch and load its commits
            const currentIdx = this.allFiltered.findIndex(b => b.name === this.selectedBranch);
            if (currentIdx >= 0) this.selectedIndex = currentIdx;
            this.selectCurrentBranch();
            await this.$nextTick();
            this.$refs.searchInput?.focus();
        },

        closePanel() {
            this.open = false;
        },

        selectCurrentBranch() {
            const branch = this.allFiltered[this.selectedIndex];
            if (branch && branch.name !== this.selectedBranch) {
                this.selectedBranch = branch.name;
                $wire.loadCommits(branch.name);
            } else if (branch && this.selectedBranch === branch.name && $wire.commits.length === 0) {
                $wire.loadCommits(branch.name);
            }
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
                    this.selectCurrentBranch();
                    this.scrollSelectedIntoView();
                }
                return;
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (this.selectedIndex > 0) {
                    this.selectedIndex--;
                    this.selectCurrentBranch();
                    this.scrollSelectedIntoView();
                }
                return;
            }
        },

        onSearchChange() {
            this.selectedIndex = 0;
            this.selectCurrentBranch();
        },

        scrollSelectedIntoView() {
            this.$nextTick(() => {
                this.$refs.branchList?.querySelector('[data-selected=true]')?.scrollIntoView({ block: 'nearest' });
            });
        },

        selectBranchAt(index) {
            this.selectedIndex = index;
            this.selectCurrentBranch();
        },

        copyHash(hash) {
            navigator.clipboard.writeText(hash).catch(() => {});
        }
    }"
    @keydown.window="handleKeydown($event)"
>
    {{-- Trigger: branch badge button --}}
    <button
        @click="openPanel()"
        class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded border border-gh-border hover:border-gh-accent/50 bg-gh-surface text-gh-text transition-colors cursor-pointer"
    >
        <flux:icon icon="share" variant="micro" class="text-gh-muted" />
        <span>{{ $currentBranch }}</span>
        <flux:icon icon="chevron-down" variant="micro" class="text-gh-muted" />
    </button>

    {{-- Overlay panel --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[60]" @click.self="closePanel()">
            {{-- Backdrop --}}
            <div class="absolute inset-0 bg-black/30" @click="closePanel()"></div>

            {{-- Panel --}}
            <div
                class="fixed top-[15vh] left-1/2 -translate-x-1/2 z-[61] w-[700px] max-w-[90vw] max-h-[60vh] bg-gh-surface border border-gh-border rounded-xl shadow-2xl flex overflow-hidden"
                @click.stop
            >
                {{-- Left pane: branches --}}
                <div class="w-[240px] shrink-0 border-r border-gh-border flex flex-col max-h-[60vh]">
                    {{-- Search input --}}
                    <div class="p-2 border-b border-gh-border">
                        <input
                            x-ref="searchInput"
                            x-model.debounce.100ms="search"
                            @input="onSearchChange()"
                            type="text"
                            placeholder="Filter branches..."
                            class="w-full bg-gh-bg border border-gh-border rounded px-2 py-1.5 text-xs text-gh-text placeholder-gh-muted focus:outline-none focus:border-gh-accent"
                        />
                    </div>

                    {{-- Branch list --}}
                    <div class="overflow-y-auto flex-1" x-ref="branchList">
                        {{-- Local branches --}}
                        <template x-if="filteredLocal.length > 0">
                            <div>
                                <div class="px-3 pt-2 pb-1">
                                    <span class="text-[10px] uppercase tracking-wider font-semibold text-gh-muted" x-text="localLabel"></span>
                                </div>
                                <template x-for="(branch, i) in filteredLocal" :key="branch.name">
                                    <button
                                        @click="selectBranchAt(i)"
                                        class="w-full text-left px-3 py-1.5 text-xs flex items-center gap-2 transition-colors"
                                        :class="selectedIndex === i ? 'bg-gh-accent/10 text-gh-accent' : 'text-gh-text hover:bg-gh-border/50'"
                                        :data-selected="selectedIndex === i"
                                    >
                                        <flux:icon icon="check" variant="micro" class="shrink-0" x-show="branch.isCurrent" x-cloak />
                                        <span class="shrink-0 w-3" x-show="!branch.isCurrent"></span>
                                        <span class="truncate" x-text="branch.name"></span>
                                    </button>
                                </template>
                            </div>
                        </template>

                        {{-- Remote branches --}}
                        <template x-if="filteredRemote.length > 0">
                            <div>
                                <div class="px-3 pt-3 pb-1 border-t border-gh-border">
                                    <span class="text-[10px] uppercase tracking-wider font-semibold text-gh-muted" x-text="remoteLabel"></span>
                                </div>
                                <template x-for="(branch, j) in filteredRemote" :key="branch.name">
                                    <button
                                        @click="selectBranchAt(filteredLocal.length + j)"
                                        class="w-full text-left px-3 py-1.5 text-xs flex items-center gap-2 transition-colors"
                                        :class="selectedIndex === (filteredLocal.length + j) ? 'bg-gh-accent/10 text-gh-accent' : 'text-gh-text hover:bg-gh-border/50'"
                                        :data-selected="selectedIndex === (filteredLocal.length + j)"
                                    >
                                        <span class="shrink-0 w-3"></span>
                                        <span class="truncate" x-text="branch.name"></span>
                                    </button>
                                </template>
                            </div>
                        </template>

                        {{-- Empty state --}}
                        <template x-if="allFiltered.length === 0">
                            <div class="px-3 py-6 text-center">
                                <span class="text-xs text-gh-muted">No branches found</span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Right pane: commits --}}
                <div class="flex-1 flex flex-col max-h-[60vh] min-w-0">
                    {{-- Commits header --}}
                    <div class="px-4 py-2 border-b border-gh-border flex items-center gap-2 shrink-0">
                        <flux:icon icon="clock" variant="micro" class="text-gh-muted" />
                        <span class="text-xs font-semibold text-gh-text truncate" x-text="selectedBranch || 'Select a branch'"></span>
                        <span class="text-xs text-gh-muted" x-show="$wire.commits.length > 0" x-text="'(' + $wire.commits.length + (hasMore ? '+' : '') + ')'"></span>
                    </div>

                    {{-- Commits list --}}
                    <div class="overflow-y-auto flex-1">
                        <template x-if="$wire.commits.length === 0">
                            <div class="flex items-center justify-center h-32">
                                <span class="text-xs text-gh-muted">No commits</span>
                            </div>
                        </template>

                        <template x-for="commit in $wire.commits" :key="commit.hash">
                            <div class="px-4 py-2 border-b border-gh-border/50 hover:bg-gh-border/30 transition-colors group">
                                <div class="flex items-start gap-2">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-xs text-gh-text truncate" x-text="commit.message"></div>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-[10px] text-gh-muted" x-text="commit.author"></span>
                                            <span class="text-[10px] text-gh-muted">·</span>
                                            <span class="text-[10px] text-gh-muted" x-text="commit.relativeDate"></span>
                                        </div>
                                    </div>
                                    <button
                                        @click.stop="copyHash(commit.hash)"
                                        class="shrink-0 px-1.5 py-0.5 rounded text-[10px] font-mono bg-gh-bg border border-gh-border text-gh-muted hover:text-gh-accent hover:border-gh-accent/50 opacity-0 group-hover:opacity-100 transition-all cursor-pointer"
                                        x-text="commit.shortHash"
                                        title="Copy full hash"
                                    ></button>
                                </div>
                            </div>
                        </template>

                        {{-- Load more --}}
                        <template x-if="hasMore">
                            <div class="px-4 py-3 text-center">
                                <button
                                    @click="$wire.loadMore(selectedBranch)"
                                    class="text-xs text-gh-accent hover:underline cursor-pointer"
                                >
                                    Load more commits...
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<?php

use App\Actions\GetBranchListAction;
use App\Actions\GetCommitHistoryAction;
use App\Actions\ResolveBranchBaseAction;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public string $repoPath = '';

    #[Locked]
    public string $currentBranch = '';

    #[Locked]
    public string $projectSlug = '';

    #[Locked]
    public ?string $activeCommitHash = null;

    /** Left-endpoint of the active diff. 'HEAD' for plain working-tree view; a commit sha for range views. */
    #[Locked]
    public string $activeDiffFrom = 'HEAD';

    #[Locked]
    public bool $hasRemote = false;

    #[Locked]
    public string $selectionLabel = 'Working tree';

    #[Locked]
    public string $selectionTitle = 'Working tree changes';

    /** Configured project base branch (e.g. 'dev'). Empty means the user hasn't set one yet. */
    #[Locked]
    public string $defaultBaseBranch = '';

    /** @var array{local: list<array<string, mixed>>, remote: list<array<string, mixed>>, current: string} */
    public array $branches = ['local' => [], 'remote' => [], 'current' => ''];

    /** @var list<array<string, mixed>> */
    public array $commits = [];

    /**
     * Result of {@see ResolveBranchBaseAction} for the project's current branch.
     * Surfaced so the picker can render the "Since {base}" row and pre-fill
     * checkboxes when clicked.
     *
     * @var array{state: string, baseBranch: ?string, baseSha: ?string, hashesInRange: list<string>, commitCount: int}|null
     */
    public ?array $branchBase = null;

    public bool $hasMore = false;

    private int $pageSize = 50;

    #[Renderless]
    public function loadBranches(): void
    {
        $this->branches = app(GetBranchListAction::class)->handle($this->repoPath);
    }

    #[Renderless]
    public function loadBranchBase(): void
    {
        $result = app(ResolveBranchBaseAction::class)->handle(
            $this->repoPath,
            $this->defaultBaseBranch !== '' ? $this->defaultBaseBranch : null,
            $this->currentBranch !== '' ? $this->currentBranch : null,
        );

        $this->branchBase = $result->toArray();
    }

    /**
     * Load commits for the displayed branch. When the picker is showing the
     * project's current branch and the configured base is more than `pageSize`
     * commits behind, the limit is bumped so every commit in `base..HEAD`
     * is loaded - otherwise the auto-select on the "Since {base}" row would
     * tick checkboxes that aren't rendered.
     */
    #[Renderless]
    public function loadCommits(string $branch): void
    {
        $limit = $this->effectiveCommitLimit($branch);
        $commits = app(GetCommitHistoryAction::class)->handle($this->repoPath, $limit, 0, $branch);

        $this->commits = $commits;
        $this->hasMore = count($commits) >= $limit;
    }

    #[Renderless]
    public function loadMore(string $branch): void
    {
        $offset = count($this->commits);
        $more = app(GetCommitHistoryAction::class)->handle($this->repoPath, $this->pageSize, $offset, $branch);

        $this->commits = array_merge($this->commits, $more);
        $this->hasMore = count($more) >= $this->pageSize;
    }

    private function effectiveCommitLimit(string $branch): int
    {
        if ($branch !== $this->currentBranch || $this->branchBase === null) {
            return $this->pageSize;
        }

        return max($this->pageSize, $this->branchBase['commitCount']);
    }
};

?>

@assets
<script src="/js/branch-explorer.js"></script>
@endassets

<div
    x-data="branchExplorer({
        currentBranch: @js($currentBranch),
        activeCommitHash: @js($activeCommitHash),
        activeDiffFrom: @js($activeDiffFrom),
        projectSlug: @js($projectSlug),
        branches: @js($branches),
    })"
    x-init="$store.keymap.register('⌘B', () => open ? closePanel() : openPanel())"
    @keydown.window="handleKeydown($event)"
    @open-selection-drawer.window="openPanel()"
    @if($hasRemote) @contextmenu="handleRemoteContextMenu($event)" @endif
    x-effect="if (open && !$store.overlays.is('branch-explorer')) closePanel()"
>
    <div class="inline-flex items-stretch rounded-md border border-gh-border/70 bg-gh-surface/30 hover:border-gh-text/30 transition-colors">
        <div @if($hasRemote) @contextmenu.prevent="openRemoteContext($event, 'branch', { name: currentBranch }, 'branch ' + currentBranch)" @endif class="inline-flex">
            <flux:tooltip content="Switch branch · ⌘B">
                <button
                    type="button"
                    class="group inline-flex items-center gap-1.5 px-2 py-1 text-xs font-mono text-gh-muted hover:text-gh-text hover:bg-gh-border/25 rounded-l-md transition-colors cursor-pointer"
                    aria-label="Switch branch (⌘B)"
                    aria-haspopup="dialog"
                    x-on:click="openPanel()"
                    x-bind:aria-expanded="open"
                >
                    <flux:icon icon="share" variant="outline" class="!size-3 text-gh-muted/70 group-hover:text-gh-text transition-colors" />
                    <span class="tracking-tight">{{ $currentBranch }}</span>
                </button>
            </flux:tooltip>
        </div>

        <span class="w-px self-stretch bg-gh-border/70" aria-hidden="true"></span>

        <div @if($hasRemote) @contextmenu.prevent="openSelectionRemoteContext($event)" @endif class="inline-flex">
            <flux:tooltip content="{{ $selectionTitle }} · ⌘B">
                <button
                    type="button"
                    class="group inline-flex items-center gap-1.5 px-2 py-1 text-xs font-mono text-gh-text hover:bg-gh-border/25 rounded-r-md transition-colors cursor-pointer"
                    aria-label="Open selection drawer (⌘B)"
                    aria-haspopup="dialog"
                    x-on:click="openPanel()"
                    x-bind:aria-expanded="open"
                >
                    <flux:icon icon="square-3-stack-3d" variant="outline" class="!size-3 text-gh-muted/70 group-hover:text-gh-text transition-colors" />
                    <span>{{ $selectionLabel }}</span>
                    <x-chevron-icon variant="mono" />
                </button>
            </flux:tooltip>
        </div>
    </div>

    <x-overlay-panel name="branch-explorer" aria-label="Switch branch" size="lg" on-close="closePanel()">
        <div class="flex flex-1 min-h-0">
                {{-- Left pane: branches --}}
                <div class="w-[180px] shrink-0 border-r border-gh-border flex flex-col min-h-0">
                    {{-- Search input --}}
                    <div class="px-2 py-3 border-b border-gh-border">
                        <flux:input
                            x-ref="searchInput"
                            x-model.debounce.100ms="search"
                            @input="onSearchChange()"
                            @keydown.escape.stop="handleSearchEscape($event)"
                            placeholder="Filter branches..."
                            icon="magnifying-glass"
                            icon:variant="outline"
                            size="sm"
                            variant="filled"
                        />
                    </div>

                    {{-- Branch list --}}
                    <div class="overflow-y-auto flex-1" x-ref="branchList">
                        {{-- Local branches --}}
                        <template x-if="filteredLocal.length > 0">
                            <div>
                                <div class="px-3 pt-3 pb-1">
                                    <span class="section-label text-gh-muted">Local</span>
                                </div>
                                <template x-for="(branch, i) in filteredLocal" :key="branch.name">
                                    <div
                                        @if($hasRemote)
                                            data-remote-context
                                            data-remote-type="branch"
                                            :data-remote-params="JSON.stringify({ name: branch.name })"
                                            :data-remote-label="'branch ' + branch.name"
                                        @endif
                                    >
                                        <button
                                            @click="selectBranchAt(i)"
                                            class="w-full text-left px-3 py-2 text-xs font-mono flex items-center gap-2 transition-colors cursor-pointer"
                                            :class="selectedIndex === i ? 'bg-gh-text/10 text-gh-text font-medium' : 'text-gh-muted hover:bg-gh-border/30 hover:text-gh-text'"
                                            :data-selected="selectedIndex === i"
                                            :title="branch.name"
                                        >
                                            <flux:icon icon="check" variant="outline" class="shrink-0" x-show="branch.isCurrent" x-cloak />
                                            <span class="shrink-0 w-3" x-show="!branch.isCurrent"></span>
                                            <span class="truncate" x-text="branch.name"></span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Remote branches --}}
                        <template x-if="filteredRemote.length > 0">
                            <div>
                                <div class="px-3 pt-3 pb-1 border-t border-gh-border">
                                    <span class="section-label text-gh-muted">Remote</span>
                                </div>
                                <template x-for="(branch, j) in filteredRemote" :key="branch.name">
                                    <div
                                        @if($hasRemote)
                                            data-remote-context
                                            data-remote-type="branch"
                                            :data-remote-params="JSON.stringify({ name: branch.remote && branch.name.startsWith(branch.remote + '/') ? branch.name.slice(branch.remote.length + 1) : branch.name })"
                                            :data-remote-label="'branch ' + branch.name"
                                        @endif
                                    >
                                        <button
                                            @click="selectBranchAt(filteredLocal.length + j)"
                                            class="w-full text-left px-3 py-2 text-xs font-mono flex items-center gap-2 transition-colors cursor-pointer"
                                            :class="selectedIndex === (filteredLocal.length + j) ? 'bg-gh-text/10 text-gh-text font-medium' : 'text-gh-muted hover:bg-gh-border/30 hover:text-gh-text'"
                                            :data-selected="selectedIndex === (filteredLocal.length + j)"
                                            :title="branch.name"
                                        >
                                            <span class="shrink-0 w-3"></span>
                                            <span class="truncate" x-text="branch.name"></span>
                                        </button>
                                    </div>
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
                <div class="flex-1 flex flex-col min-h-0 min-w-0">
                    {{-- Commits header --}}
                    <div class="px-4 py-2.5 border-b border-gh-border flex items-center gap-2 shrink-0">
                        <flux:icon icon="clock" variant="outline" class="text-gh-muted" />
                        <span class="text-xs font-semibold tracking-brutal text-gh-text truncate" x-text="selectedBranch || 'Select a branch'" :title="selectedBranch"></span>
                        <span class="text-xs font-mono text-gh-muted" x-show="$wire.commits.length > 0" x-text="'(' + $wire.commits.length + ($wire.hasMore ? '+' : '') + ')'"></span>

                        <template x-if="hasAnySelection">
                            <div class="ml-auto flex items-center gap-1">
                                {{-- Segmented apply button: count + label + apply act as one unit --}}
                                <button
                                    type="button"
                                    @click="applySelection()"
                                    class="group flex items-stretch h-6 rounded-md overflow-hidden ring-1 ring-inset ring-gh-link/30 bg-gh-link/10 hover:bg-gh-link/20 hover:ring-gh-link/50 transition-colors cursor-pointer"
                                    :aria-label="'Apply ' + selectionDescription"
                                >
                                    <span
                                        class="grid place-items-center min-w-[18px] h-full px-1 bg-gh-link text-[10px] font-mono font-bold text-gh-bg tabular-nums leading-none"
                                        x-text="selectionBadge"
                                    ></span>
                                    <span class="flex items-center px-2 text-[9px] font-display font-semibold uppercase tracking-brutal text-gh-link/80 border-r border-gh-link/25">
                                        selected
                                    </span>
                                    <span class="flex items-center gap-1 px-2 text-gh-link font-display text-[10px] font-bold uppercase tracking-brutal">
                                        <span>Apply</span>
                                        <flux:icon icon="arrow-right" variant="outline" class="!size-3 transition-transform group-hover:translate-x-0.5" />
                                    </span>
                                </button>

                                {{-- Clear --}}
                                <button
                                    type="button"
                                    @click="clearSelection()"
                                    class="flex items-center justify-center size-6 rounded-md text-gh-muted hover:text-gh-text hover:bg-gh-border/40 transition-colors cursor-pointer"
                                    title="Clear selection"
                                    aria-label="Clear selection"
                                >
                                    <flux:icon icon="x-mark" variant="outline" class="!size-3.5" />
                                </button>
                            </div>
                        </template>
                    </div>

                    {{-- Commits list --}}
                    <div class="overflow-y-auto flex-1">
                        {{-- "Since {base}" shortcut: a single click fills the
                             multi-select with every commit in base..HEAD plus
                             working tree, so the user can trim before applying. --}}
                        <template x-if="sinceBaseRowVisible">
                            <div data-testid="since-base-row">
                                {{-- Ready: clickable shortcut --}}
                                <template x-if="$wire.branchBase.state === 'ready'">
                                    <div
                                        class="px-4 py-2.5 border-b border-gh-border/50 hover:bg-gh-border/20 transition-colors group cursor-pointer"
                                        @click="selectSinceBase()"
                                        :class="{ 'bg-gh-link/5 border-l-2 border-l-gh-link': sinceBaseSelected }"
                                        :aria-pressed="sinceBaseSelected"
                                    >
                                        <div class="flex items-start gap-2">
                                            <span
                                                class="mt-0.5 size-4 shrink-0 grid place-items-center rounded border transition-colors"
                                                :class="sinceBaseSelected
                                                    ? 'border-gh-link bg-gh-link/20 text-gh-link'
                                                    : 'border-gh-border opacity-0 group-hover:opacity-100'"
                                                aria-hidden="true"
                                            >
                                                <template x-if="sinceBaseSelected">
                                                    <flux:icon icon="check" variant="outline" class="!size-3" />
                                                </template>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-xs text-gh-text truncate font-medium tracking-tight">
                                                    Since <span class="font-mono" x-text="$wire.branchBase.baseBranch"></span>
                                                </div>
                                                <span
                                                    class="block mt-0.5 text-[10px] font-mono text-gh-muted"
                                                    x-text="$wire.branchBase.commitCount + ' commit' + ($wire.branchBase.commitCount === 1 ? '' : 's') + ' + uncommitted changes'"
                                                ></span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Up to date with base: dimmed, not actionable --}}
                                <template x-if="$wire.branchBase.state === 'up_to_date'">
                                    <div class="px-4 py-2.5 border-b border-gh-border/50">
                                        <div class="flex items-start gap-2 opacity-60">
                                            <span class="mt-0.5 size-4 shrink-0" aria-hidden="true"></span>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-xs text-gh-text truncate tracking-tight">
                                                    Up to date with <span class="font-mono" x-text="$wire.branchBase.baseBranch"></span>
                                                </div>
                                                <span class="block mt-0.5 text-[10px] font-mono text-gh-muted">no commits ahead</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Missing ref: actionable hint --}}
                                <template x-if="$wire.branchBase.state === 'missing_ref'">
                                    <div class="px-4 py-2.5 border-b border-gh-border/50">
                                        <div class="flex items-start gap-2">
                                            <span class="mt-0.5 size-4 shrink-0 text-gh-muted" aria-hidden="true">
                                                <flux:icon icon="exclamation-triangle" variant="outline" class="!size-3.5" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-xs text-gh-text truncate tracking-tight">
                                                    Run <span class="font-mono">git fetch</span> to compare with <span class="font-mono" x-text="$wire.branchBase.baseBranch"></span>
                                                </div>
                                                <span class="block mt-0.5 text-[10px] font-mono text-gh-muted">base ref not found locally</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Not configured: nudge to settings --}}
                                <template x-if="$wire.branchBase.state === 'not_configured'">
                                    <div class="px-4 py-2.5 border-b border-gh-border/50">
                                        <div class="flex items-start gap-2 opacity-80">
                                            <span class="mt-0.5 size-4 shrink-0 text-gh-muted" aria-hidden="true">
                                                <flux:icon icon="cog-6-tooth" variant="outline" class="!size-3.5" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-xs text-gh-text truncate tracking-tight">Pick a base branch to compare against</div>
                                                <span class="block mt-0.5 text-[10px] font-mono text-gh-muted">set it from the project settings menu</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- on_base_branch: hidden entirely (comparing X..X is nonsense) --}}
                            </div>
                        </template>

                        <div
                            data-testid="working-tree-row"
                            class="px-4 py-2.5 border-b border-gh-border/50 hover:bg-gh-border/20 transition-colors group cursor-pointer"
                            @click="viewWorkingTree()"
                            :class="{
                                'bg-gh-text/5 border-l-2 border-l-gh-text': isWorkingTreeActive,
                                'bg-gh-link/5 border-l-2 border-l-gh-link': workingTreeSelected,
                            }"
                            :aria-current="isWorkingTreeActive ? 'true' : null"
                        >
                            <div class="flex items-start gap-2">
                                <button
                                    type="button"
                                    data-testid="working-tree-select-toggle"
                                    @click.stop="toggleWorkingTreeSelection($event)"
                                    @mousedown.stop
                                    class="mt-0.5 size-4 shrink-0 grid place-items-center rounded border transition-colors cursor-pointer"
                                    :class="workingTreeSelected
                                        ? 'border-gh-link bg-gh-link/20 text-gh-link'
                                        : 'border-gh-border opacity-0 group-hover:opacity-100 hover:border-gh-text/40'"
                                    :title="workingTreeSelected ? 'Remove working tree from selection' : 'Add working tree to selection (shift+click for range)'"
                                >
                                    <template x-if="workingTreeSelected">
                                        <flux:icon icon="check" variant="outline" class="!size-3" />
                                    </template>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <div class="text-xs text-gh-text truncate font-medium tracking-tight">Working tree</div>
                                    <span class="block mt-0.5 text-[10px] font-mono text-gh-muted">uncommitted changes</span>
                                </div>
                            </div>
                        </div>

                        <template x-if="$wire.commits.length === 0">
                            <div class="flex items-center justify-center h-32">
                                <span class="text-xs text-gh-muted">No commits</span>
                            </div>
                        </template>

                        <template x-for="(commit, commitIdx) in $wire.commits" :key="commit.hash">
                            <div
                                data-testid="commit-row"
                                :data-commit-hash="commit.hash"
                                :data-commit-idx="commitIdx"
                                @if($hasRemote)
                                    data-remote-context
                                    data-remote-type="commit"
                                    :data-remote-params="JSON.stringify({ sha: commit.hash })"
                                    :data-remote-label="'commit ' + commit.shortHash"
                                @endif
                                class="px-4 py-2.5 border-b border-gh-border/50 hover:bg-gh-border/20 transition-colors group cursor-pointer"
                                @click="viewCommit(commit.hash)"
                                :class="{
                                    'bg-gh-text/5 border-l-2 border-l-gh-text': activeCommitHash === commit.hash,
                                    'bg-gh-link/5 border-l-2 border-l-gh-link': isSelected(commit.hash),
                                }"
                            >
                                <div class="flex items-start gap-2">
                                    <button
                                        type="button"
                                        data-testid="commit-select-toggle"
                                        @click.stop="toggleSelection(commit.hash, commitIdx, $event)"
                                        @mousedown.stop="startDrag(commitIdx, $event)"
                                        class="mt-0.5 size-4 shrink-0 grid place-items-center rounded border transition-colors cursor-pointer"
                                        :class="isSelected(commit.hash)
                                            ? 'border-gh-link bg-gh-link/20 text-gh-link'
                                            : 'border-gh-border opacity-0 group-hover:opacity-100 hover:border-gh-text/40'"
                                        :title="isSelected(commit.hash) ? 'Remove from selection' : 'Click to add, drag to extend range'"
                                    >
                                        <template x-if="isSelected(commit.hash)">
                                            <flux:icon icon="check" variant="outline" class="!size-3" />
                                        </template>
                                    </button>
                                    <div class="min-w-0 flex-1">
                                        <div data-testid="commit-message" class="text-xs text-gh-text truncate font-medium tracking-tight" x-text="commit.message" :title="commit.message"></div>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-[10px] font-mono text-gh-muted" x-text="commit.author"></span>
                                            <span class="text-[10px] text-gh-muted" aria-hidden="true">&middot;</span>
                                            <span class="text-[10px] font-mono text-gh-muted" x-text="commit.relativeDate" :title="commit.date"></span>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity" @click.stop>
                                        <button
                                            @click.stop="copyHash(commit.hash)"
                                            class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-gh-bg border border-gh-border text-gh-muted hover:text-gh-link hover:border-gh-link/50 transition-all cursor-pointer"
                                            x-text="commit.shortHash"
                                            title="Copy full hash"
                                        ></button>
                                    </div>
                                </div>
                            </div>
                        </template>

                        {{-- Load more --}}
                        <template x-if="$wire.hasMore">
                            <div class="px-4 py-3 text-center">
                                <button
                                    @click="$wire.loadMore(selectedBranch)"
                                    class="text-xs text-gh-link hover:underline cursor-pointer"
                                >
                                    Load more commits...
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
        </div>

        <x-overlay-footer>
            <x-slot:meta>
                <span x-text="(() => {
                    const n = $wire.commits?.length || 0;
                    const noun = (n === 1 && !$wire.hasMore) ? 'commit' : 'commits';
                    return n + ($wire.hasMore ? '+' : '') + ' ' + noun;
                })()"></span>
            </x-slot:meta>
        </x-overlay-footer>
    </x-overlay-panel>
</div>

<?php

use App\Actions\GetBranchListAction;
use App\Actions\GetCommitHistoryAction;
use App\Concerns\InteractsWithRemoteLinks;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use InteractsWithRemoteLinks;

    #[Locked]
    public string $repoPath = '';

    #[Locked]
    public string $currentBranch = '';

    #[Locked]
    public string $projectSlug = '';

    #[Locked]
    public ?string $activeCommitHash = null;

    #[Locked]
    public string $selectionLabel = 'Working tree';

    #[Locked]
    public string $selectionTitle = 'Working tree changes';

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

@assets
<script src="/js/branch-explorer.js"></script>
@endassets

<div
    x-data="branchExplorer({
        currentBranch: @js($currentBranch),
        activeCommitHash: @js($activeCommitHash),
        projectSlug: @js($projectSlug),
        branches: @js($branches),
    })"
    x-init="$store.keymap.register('⌘B', () => open ? closePanel() : openPanel())"
    @keydown.window="handleKeydown($event)"
    @open-selection-drawer.window="openPanel()"
    x-effect="if (open && !$store.overlays.is('branch-explorer')) closePanel()"
>
    <div class="inline-flex items-stretch rounded-md border border-gh-border/70 bg-gh-surface/30 hover:border-gh-text/30 transition-colors">
        <div x-data="contextMenu()" @contextmenu.prevent="openCtx($event)" class="inline-flex">
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
            <x-remote-link-menu
                :project-slug="$projectSlug"
                type="branch"
                :params="['name' => $currentBranch]"
                label="branch"
            />
        </div>

        <span class="w-px self-stretch bg-gh-border/70" aria-hidden="true"></span>

        <div x-data="contextMenu()" @contextmenu.prevent="openCtx($event)" class="inline-flex">
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
            @if($activeCommitHash !== null)
                <x-remote-link-menu
                    :project-slug="$projectSlug"
                    type="commit"
                    :params="['sha' => $activeCommitHash]"
                    label="commit"
                />
            @else
                <x-remote-link-menu
                    :project-slug="$projectSlug"
                    type="branch"
                    :params="['name' => $currentBranch]"
                    label="branch"
                />
            @endif
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
                            placeholder="Filter branch"
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
                                    <div x-data="contextMenu()" @contextmenu.prevent="openCtx($event)">
                                        <button
                                            @click="selectBranchAt(i)"
                                            class="w-full text-left px-3 py-2 text-xs font-mono flex items-center gap-2 transition-colors cursor-pointer"
                                            :class="selectedIndex === i ? 'bg-gh-text/10 text-gh-text font-medium' : 'text-gh-muted hover:bg-gh-border/30 hover:text-gh-text'"
                                            :data-selected="selectedIndex === i"
                                        >
                                            <flux:icon icon="check" variant="outline" class="shrink-0" x-show="branch.isCurrent" x-cloak />
                                            <span class="shrink-0 w-3" x-show="!branch.isCurrent"></span>
                                            <span class="truncate" x-text="branch.name"></span>
                                        </button>
                                        <x-remote-link-menu
                                            :project-slug="$projectSlug"
                                            type-js="'branch'"
                                            params-js="{ name: branch.name }"
                                            label-js="'branch ' + branch.name"
                                        />
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
                                    <div x-data="contextMenu()" @contextmenu.prevent="openCtx($event)">
                                        <button
                                            @click="selectBranchAt(filteredLocal.length + j)"
                                            class="w-full text-left px-3 py-2 text-xs font-mono flex items-center gap-2 transition-colors cursor-pointer"
                                            :class="selectedIndex === (filteredLocal.length + j) ? 'bg-gh-text/10 text-gh-text font-medium' : 'text-gh-muted hover:bg-gh-border/30 hover:text-gh-text'"
                                            :data-selected="selectedIndex === (filteredLocal.length + j)"
                                        >
                                            <span class="shrink-0 w-3"></span>
                                            <span class="truncate" x-text="branch.name"></span>
                                        </button>
                                        <x-remote-link-menu
                                            :project-slug="$projectSlug"
                                            type-js="'branch'"
                                            params-js="{ name: branch.remote && branch.name.startsWith(branch.remote + '/') ? branch.name.slice(branch.remote.length + 1) : branch.name }"
                                            label-js="'branch ' + branch.name"
                                        />
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
                        <span class="text-xs font-semibold tracking-brutal text-gh-text truncate" x-text="selectedBranch || 'Select a branch'"></span>
                        <span class="text-xs font-mono text-gh-muted" x-show="$wire.commits.length > 0" x-text="'(' + $wire.commits.length + ($wire.hasMore ? '+' : '') + ')'"></span>

                        <template x-if="selectedHashes.length > 0">
                            <div class="ml-auto flex items-center gap-1">
                                {{-- Segmented apply button: count + label + apply act as one unit --}}
                                <button
                                    type="button"
                                    @click="applySelection()"
                                    class="group flex items-stretch h-6 rounded-md overflow-hidden ring-1 ring-inset ring-gh-link/30 bg-gh-link/10 hover:bg-gh-link/20 hover:ring-gh-link/50 transition-colors cursor-pointer"
                                    :aria-label="'Apply ' + selectedHashes.length + ' selected commits'"
                                >
                                    <span
                                        class="grid place-items-center min-w-[18px] h-full px-1 bg-gh-link text-[10px] font-mono font-bold text-gh-bg tabular-nums leading-none"
                                        x-text="selectedHashes.length"
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
                        <button
                            type="button"
                            data-testid="working-tree-row"
                            class="w-full text-left px-4 py-2.5 border-b border-gh-border/50 hover:bg-gh-border/20 transition-colors cursor-pointer"
                            @click="viewWorkingTree()"
                            :class="{ 'bg-gh-text/5 border-l-2 border-l-gh-text': activeCommitHash === null }"
                            :aria-current="activeCommitHash === null ? 'true' : null"
                        >
                            <div class="flex items-start gap-2">
                                <div class="mt-0.5 size-4 shrink-0 grid place-items-center">
                                    <flux:icon icon="pencil-square" variant="outline" class="!size-3.5 text-gh-muted" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-xs text-gh-text truncate font-medium tracking-tight">Working tree</div>
                                    <span class="block mt-0.5 text-[10px] font-mono text-gh-muted">uncommitted changes</span>
                                </div>
                            </div>
                        </button>

                        <template x-if="$wire.commits.length === 0">
                            <div class="flex items-center justify-center h-32">
                                <span class="text-xs text-gh-muted">No commits</span>
                            </div>
                        </template>

                        <template x-for="(commit, commitIdx) in $wire.commits" :key="commit.hash">
                            <div
                                data-testid="commit-row"
                                :data-commit-hash="commit.hash"
                                x-data="contextMenu()"
                                @contextmenu.prevent="openCtx($event)"
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
                                        @mousedown.stop
                                        class="mt-0.5 size-4 shrink-0 grid place-items-center rounded border transition-colors cursor-pointer"
                                        :class="isSelected(commit.hash)
                                            ? 'border-gh-link bg-gh-link/20 text-gh-link'
                                            : 'border-gh-border opacity-0 group-hover:opacity-100 hover:border-gh-text/40'"
                                        :title="isSelected(commit.hash) ? 'Remove from selection' : 'Add to selection (shift+click for range)'"
                                    >
                                        <template x-if="isSelected(commit.hash)">
                                            <flux:icon icon="check" variant="outline" class="!size-3" />
                                        </template>
                                    </button>
                                    <div class="min-w-0 flex-1">
                                        <div data-testid="commit-message" class="text-xs text-gh-text truncate font-medium tracking-tight" x-text="commit.message"></div>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-[10px] font-mono text-gh-muted" x-text="commit.author"></span>
                                            <span class="text-[10px] text-gh-muted">&middot;</span>
                                            <span class="text-[10px] font-mono text-gh-muted" x-text="commit.relativeDate"></span>
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
                                <x-remote-link-menu
                                    :project-slug="$projectSlug"
                                    type-js="'commit'"
                                    params-js="{ sha: commit.hash }"
                                    label-js="'commit ' + commit.shortHash"
                                />
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
                <span x-text="($wire.commits?.length || 0) + ($wire.hasMore ? '+' : '') + ' commits'"></span>
            </x-slot:meta>
        </x-overlay-footer>
    </x-overlay-panel>
</div>

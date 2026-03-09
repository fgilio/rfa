<?php

use App\Actions\ListProjectsAction;
use App\Actions\RemoveProjectAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $projectGroups = [];

    public string $sortBy = 'recent';

    public function mount(): void
    {
        $this->projectGroups = app(ListProjectsAction::class)->handle($this->sortBy);
    }

    public function loadProjects(string $sortBy = 'recent'): void
    {
        $this->sortBy = $sortBy;
        $this->projectGroups = app(ListProjectsAction::class)->handle($this->sortBy);
    }

    public function removeProject(int $projectId): void
    {
        app(RemoveProjectAction::class)->handle($projectId);

        $this->projectGroups = app(ListProjectsAction::class)->handle($this->sortBy);
    }
};
?>

<div
    class="min-h-screen"
    wire:poll.30s="loadProjects('{{ $sortBy }}')"
    x-data="{
        search: '',
        selectedIndex: -1,
        selectedProjectId: null,
        sortBy: localStorage.getItem('rfa-sort') || 'recent',
        get flatProjects() {
            return Array.from(this.$root.querySelectorAll('[data-project-card]'));
        },
        get visibleProjects() {
            return this.flatProjects.filter(el => el.style.display !== 'none');
        },
        matchesSearch(name, branch, path) {
            if (this.search === '') return true;
            const q = this.search.toLowerCase();
            return name.toLowerCase().includes(q) || (branch && branch.toLowerCase().includes(q)) || path.toLowerCase().includes(q);
        },
        navigate(dir) {
            const visible = this.visibleProjects;
            if (!visible.length) return;
            this.selectedIndex = Math.max(0, Math.min(visible.length - 1, this.selectedIndex + dir));
            this.selectedProjectId = visible[this.selectedIndex]?.dataset.projectId ?? null;
            visible[this.selectedIndex]?.scrollIntoView({ block: 'nearest' });
        },
        openSelected() {
            const visible = this.visibleProjects;
            if (this.selectedIndex >= 0 && this.selectedIndex < visible.length) {
                const link = visible[this.selectedIndex]?.querySelector('a[data-project-link]');
                if (link) window.location.href = link.href;
            }
        },
        initSort() {
            if (this.sortBy !== @js($sortBy)) {
                $wire.loadProjects(this.sortBy);
            }
        },
        setSort(value) {
            this.sortBy = value;
            localStorage.setItem('rfa-sort', value);
            $wire.loadProjects(value);
        }
    }"
    x-init="initSort()"
    @keydown.window="
        if ($event.key === 'ArrowDown') { navigate(1); $event.preventDefault(); return; }
        if ($event.key === 'ArrowUp') { navigate(-1); $event.preventDefault(); return; }
        if ($event.key === 'Enter' && selectedIndex >= 0) { openSelected(); $event.preventDefault(); return; }
        if ($event.target.tagName === 'INPUT' || $event.target.tagName === 'TEXTAREA' || $event.target.isContentEditable) {
            if ($event.key === 'Escape' && $event.target.tagName === 'INPUT') { search = ''; selectedIndex = -1; selectedProjectId = null; $event.target.blur(); $event.preventDefault(); }
            return;
        }
        if ($event.key === 'Escape') { selectedIndex = -1; selectedProjectId = null; $event.preventDefault(); return; }
        if ($event.key === '/') { $refs.searchInput?.focus(); $event.preventDefault(); }
    "
>
    <header class="sticky top-0 z-50 bg-gh-bg/80 backdrop-blur-sm border-b border-gh-border px-6 py-4 flex items-center justify-between">
        <span class="rfa-logo text-2xl">rfa</span>
        <div class="flex items-center gap-3">
            <livewire:theme-switcher />
        </div>
    </header>

    <main class="max-w-2xl mx-auto py-16 px-6">
        @if(empty($projectGroups))
            <div class="flex items-center justify-center h-[60vh]">
                <div class="text-center">
                    <p class="rfa-logo text-5xl text-gh-muted/30 mb-6">rfa</p>
                    <flux:heading class="mb-3">No projects registered</flux:heading>
                    <flux:text variant="subtle" size="sm">Run <code class="font-mono bg-gh-border/50 px-1.5 py-0.5 rounded text-xs">rfa</code> from a git repository to get started</flux:text>
                </div>
            </div>
        @else
            <div class="flex items-center justify-between mb-6">
                <h1 class="rfa-logo text-4xl tracking-brutal-tight">Projects</h1>
                <div class="flex items-center gap-3">
                    <flux:button
                        variant="ghost"
                        size="sm"
                        x-on:click="setSort(sortBy === 'recent' ? 'alpha' : 'recent')"
                        class="text-gh-muted hover:text-gh-text font-mono text-xs"
                    >
                        <span x-text="sortBy === 'recent' ? 'A-Z' : 'Recent'"></span>
                    </flux:button>
                </div>
            </div>

            <div class="mb-6">
                <flux:input
                    x-model.debounce.150ms="search"
                    placeholder="Filter projects..."
                    icon="magnifying-glass"
                    icon:variant="outline"
                    size="sm"
                    variant="filled"
                    x-ref="searchInput"
                    autofocus
                    @keydown.escape="search = ''; selectedIndex = -1; selectedProjectId = null; $el.blur()"
                    @input="selectedIndex = -1; selectedProjectId = null"
                />
                @php
                    $totalProjects = collect($projectGroups)->flatten(1)->count();
                @endphp
                <div class="flex items-center justify-between mt-2">
                    <span
                        class="font-mono text-xs text-gh-muted"
                        x-text="search === ''
                            ? '{{ $totalProjects }} {{ Str::plural('project', $totalProjects) }}'
                            : visibleProjects.length + '/{{ $totalProjects }} projects'"
                    >{{ $totalProjects }} {{ Str::plural('project', $totalProjects) }}</span>
                    <span class="font-mono text-xs text-gh-muted/50" x-show="search === ''">
                        <kbd class="px-1 py-0.5 rounded border border-gh-border text-[10px]">/</kbd> search
                        <kbd class="px-1 py-0.5 rounded border border-gh-border text-[10px] ml-2">&uarr;</kbd><kbd class="px-1 py-0.5 rounded border border-gh-border text-[10px]">&darr;</kbd> navigate
                    </span>
                </div>
            </div>

            @php $projectIndex = 0; @endphp
            @foreach($projectGroups as $commonDir => $projects)
                @php
                    $groupSearchData = collect($projects)->map(fn($p) => [$p['name'], $p['branch'] ?? '', $p['path']])->values()->all();
                @endphp
                <div wire:key="group-{{ md5($commonDir) }}" class="mb-8" x-show="@js($groupSearchData).some(([n, b, p]) => matchesSearch(n, b, p))">
                    @if(count($projects) > 1)
                        <p class="section-label text-gh-muted mb-3 font-mono truncate">{{ $commonDir }}</p>
                    @endif

                    <div class="space-y-3">
                        @foreach($projects as $project)
                            <div
                                wire:key="project-{{ $project['id'] }}"
                                data-project-card
                                data-project-id="{{ $project['id'] }}"
                                data-testid="project-card"
                                x-data="{
                                    status: null,
                                    loading: true,
                                }"
                                x-intersect.once="setTimeout(() => {
                                    fetch('/api/status/{{ $project['id'] }}')
                                        .then(r => r.json())
                                        .then(d => { status = d; loading = false; })
                                        .catch(() => { loading = false; });
                                }, {{ $projectIndex * 100 }})"
                                x-show="matchesSearch(@js($project['name']), @js($project['branch'] ?? ''), @js($project['path']))"
                                :class="selectedProjectId && selectedProjectId === $el.dataset.projectId ? 'ring-1 ring-gh-link/40' : ''"
                                class="rounded-lg border border-gh-border hover:border-gh-text/30 bg-gh-surface transition-all"
                            >
                                <a href="/p/{{ $project['slug'] }}"
                                   data-project-link
                                   class="group block px-5 py-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3 min-w-0">
                                            {{-- Dirty indicator dot --}}
                                            <span class="relative flex h-2.5 w-2.5 shrink-0">
                                                <template x-if="loading">
                                                    <span class="h-2.5 w-2.5 rounded-full bg-gh-muted/30"></span>
                                                </template>
                                                <template x-if="!loading && status?.dirty">
                                                    <span class="relative flex h-2.5 w-2.5">
                                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                                                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                                                    </span>
                                                </template>
                                                <template x-if="!loading && !status?.dirty">
                                                    <span class="h-2.5 w-2.5 rounded-full bg-gh-muted/15"></span>
                                                </template>
                                            </span>

                                            <span class="font-semibold tracking-brutal text-base">{{ $project['name'] }}</span>
                                            @if($project['is_worktree'])
                                                <flux:badge size="sm" color="yellow">worktree</flux:badge>
                                            @endif
                                            @if($project['branch'])
                                                <span class="text-xs font-mono text-gh-muted px-2 py-0.5 rounded border border-gh-border">{{ $project['branch'] }}</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            @if($project['comment_count'] > 0)
                                                <span class="flex items-center gap-1 text-xs font-mono text-gh-link">
                                                    <flux:icon icon="chat-bubble-left" variant="outline" class="size-3.5" />
                                                    {{ $project['comment_count'] }}
                                                </span>
                                            @endif
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                icon:variant="outline"
                                                wire:click.prevent.stop="removeProject({{ $project['id'] }})"
                                                wire:confirm="Remove this project from the list?"
                                                class="text-gh-muted hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"
                                            />
                                            <flux:icon icon="chevron-right" variant="outline" class="text-gh-muted group-hover:text-gh-text transition-colors" />
                                        </div>
                                    </div>
                                    <div class="mt-2 flex items-center justify-between">
                                        <p class="font-mono text-xs text-gh-muted truncate">{{ $project['path'] }}</p>
                                        <div class="flex items-center gap-3 shrink-0 ml-4">
                                            <span
                                                class="font-mono text-xs"
                                                x-show="!loading && status?.dirty"
                                                x-cloak
                                            >
                                                <span class="text-gh-green" x-text="'+' + (status?.additions || 0)"></span>
                                                <span class="text-gh-red" x-text="'-' + (status?.deletions || 0)"></span>
                                            </span>
                                            <span class="font-mono text-xs text-gh-muted/60">{{ $project['last_active_ago'] }}</span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                            @php $projectIndex++; @endphp
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </main>
</div>

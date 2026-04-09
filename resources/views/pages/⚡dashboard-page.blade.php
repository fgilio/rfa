<?php

use App\Actions\ListProjectsAction;
use App\Actions\OpenProjectFromPathAction;
use App\Actions\RemoveProjectAction;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Desktop\Facades\Shell;

new #[Layout('layouts.app')] class extends Component
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $projectGroups = [];

    public string $sortBy = 'recent';

    public function mount(): void
    {
        if (config('nativephp-internal.running')) {
            \Native\Desktop\Facades\Window::get('main')->title('rfa');
        }

        $this->projectGroups = app(ListProjectsAction::class)->handle($this->sortBy);
    }

    #[On('projects-changed')]
    public function loadProjects(string $sortBy = ''): void
    {
        if ($sortBy !== '') {
            $this->sortBy = $sortBy;
        }
        $this->projectGroups = app(ListProjectsAction::class)->handle($this->sortBy);
    }

    public function removeProject(int $projectId): void
    {
        app(RemoveProjectAction::class)->handle($projectId);

        $this->projectGroups = app(ListProjectsAction::class)->handle($this->sortBy);
    }

    public function registerProject(string $path): void
    {
        $project = app(OpenProjectFromPathAction::class)->handle($path);

        if (! $project) {
            Flux::toast(variant: 'danger', text: 'Not a git repository');

            return;
        }

        $this->redirect("/p/{$project->slug}");
    }

    private const ALLOWED_EXTERNAL_URLS = [
        'https://x.com/fgili0',
        'https://github.com/fgilio/rfa',
    ];

    public function openExternal(string $url): void
    {
        if (in_array($url, self::ALLOWED_EXTERNAL_URLS, true)) {
            Shell::openExternal($url);
        }
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
        sortBy: $store.settings.dashboardSort,
        dragging: false,
        dragCounter: 0,
        get flatProjects() {
            return Array.from(this.$root.querySelectorAll('[data-project-card]'));
        },
        get visibleProjects() {
            return this.flatProjects
                .filter(el => el.style.display !== 'none')
                .sort((a, b) => {
                    const ag = parseInt(a.closest('[data-group-wrapper]')?.style.order) || 0;
                    const bg = parseInt(b.closest('[data-group-wrapper]')?.style.order) || 0;
                    return ag - bg;
                });
        },
        rankMatch(name, branch, path) {
            if (this.search === '') return 0;
            const q = this.search.toLowerCase();
            const n = name.toLowerCase();
            const b = (branch || '').toLowerCase();
            const p = path.toLowerCase();
            const esc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const ws = new RegExp('(?:^|[^a-z0-9])' + esc);
            if (n === q) return 0;
            if (n.startsWith(q)) return 1;
            if (ws.test(n)) return 2;
            if (b === q || b.startsWith(q)) return 3;
            if (ws.test(b)) return 4;
            if (ws.test(p)) return 5;
            if (n.includes(q)) return 6;
            if (b.includes(q)) return 7;
            if (p.includes(q)) return 8;
            return Infinity;
        },
        matchesSearch(name, branch, path) {
            return this.rankMatch(name, branch, path) !== Infinity;
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
        openFirst() {
            const visible = this.visibleProjects;
            if (visible.length) {
                const link = visible[0]?.querySelector('a[data-project-link]');
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
            $store.settings.dashboardSort = value;
            $wire.loadProjects(value);
        }
    }"
    x-init="initSort()"
    @dragenter.window.prevent="dragCounter++; dragging = true"
    @dragleave.window.prevent="dragCounter--; if (dragCounter <= 0) { dragging = false; dragCounter = 0; }"
    @dragover.window.prevent
    @drop.window.prevent="
        dragging = false;
        dragCounter = 0;
        const file = $event.dataTransfer?.files?.[0];
        if (file) {
            const path = window.nativeGetFilePath?.(file) || file.path;
            if (path) {
                $wire.registerProject(path);
            }
        }
    "
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
    {{-- Drop zone overlay --}}
    <div
        x-show="dragging"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        class="fixed inset-0 z-[100] flex items-center justify-center bg-gh-bg/90 backdrop-blur-sm pointer-events-none"
    >
        <div class="rounded-xl border-2 border-dashed border-gh-link/50 px-16 py-12 text-center">
            <flux:icon icon="folder-plus" variant="outline" class="size-12 text-gh-link mx-auto mb-4" />
            <p class="font-display text-lg font-semibold tracking-brutal text-gh-text">Drop folder to add project</p>
            <p class="font-mono text-xs text-gh-muted mt-1">Must be a git repository</p>
        </div>
    </div>

    @native
        <livewire:update-banner />
    @endnative

    <header class="sticky top-0 z-50 bg-gh-bg/80 backdrop-blur-sm border-b border-gh-border px-6 py-4 flex items-center justify-between">
        <div class="flex items-baseline gap-2">
            <span class="rfa-logo text-2xl">rfa</span>
            @native
                <span class="font-mono text-xs text-gh-muted">v{{ config('nativephp.version') }}</span>
            @endnative
        </div>
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
                    @native
                        <flux:text variant="subtle" size="sm" class="mb-6">Open a git repository or scan a folder to get started</flux:text>
                        <livewire:add-project-menu variant="expanded" />
                    @else
                        <flux:text variant="subtle" size="sm">Run <code class="font-mono bg-gh-border/50 px-1.5 py-0.5 rounded text-xs">rfa</code> from a git repository to get started</flux:text>
                    @endnative

                    <div class="mt-8 space-y-1">
                        <x-external-link href="https://x.com/fgili0" class="inline-flex items-center gap-1 font-mono text-xs text-gh-muted hover:text-gh-text transition-colors">
                            Made by Franco Gilio
                            <flux:icon icon="arrow-up-right" variant="outline" class="size-3" />
                        </x-external-link>
                        <p class="font-mono text-[11px] text-gh-muted/60">
                            DMs open · <x-external-link href="https://github.com/fgilio/rfa" class="hover:text-gh-muted transition-colors">PRs welcome</x-external-link>
                        </p>
                    </div>
                </div>
            </div>
        @else
            <div class="flex items-center justify-between mb-6">
                <h1 class="rfa-logo text-4xl tracking-brutal-tight">Projects</h1>
                <div class="flex items-center gap-3">
                    <livewire:add-project-menu />
                    <flux:button
                        variant="ghost"
                        size="sm"
                        x-on:click="setSort(sortBy === 'recent' ? 'alpha' : 'recent')"
                        class="text-gh-muted hover:text-gh-text font-mono text-xs"
                    >
                        <flux:icon icon="arrows-up-down" variant="outline" class="size-3.5" />
                        <span x-text="sortBy === 'recent' ? 'Recent' : 'A-Z'"></span>
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
                    @keydown.enter.prevent.stop="selectedIndex >= 0 ? openSelected() : (search !== '' && openFirst())"
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
            <div class="flex flex-col gap-8">
            @foreach($projectGroups as $commonDir => $projects)
                @php
                    $groupSearchData = collect($projects)->map(fn($p) => [$p['name'], $p['branch'] ?? '', $p['path']])->values()->all();
                @endphp
                <div
                    wire:key="group-{{ md5($commonDir) }}"
                    data-group-wrapper
                    x-show="@js($groupSearchData).some(([n, b, p]) => matchesSearch(n, b, p))"
                    :style="search ? 'order: ' + Math.min(...@js($groupSearchData).map(([n, b, p]) => rankMatch(n, b, p))) : ''"
                >
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
                                :data-selected="selectedProjectId === $el.dataset.projectId"
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
            </div>
        @endif
    </main>

    @if(!empty($projectGroups))
        <footer class="fixed bottom-0 inset-x-0 py-2 flex items-center justify-center gap-1.5 font-mono text-[11px] text-gh-muted/40">
            <x-external-link href="https://x.com/fgili0" class="hover:text-gh-muted transition-colors">Franco Gilio</x-external-link>
            <span>&middot;</span>
            <x-external-link href="https://github.com/fgilio/rfa" class="hover:text-gh-muted transition-colors">PRs welcome</x-external-link>
        </footer>
    @endif
</div>

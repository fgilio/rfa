<?php

use App\Actions\ListProjectsAction;
use App\Actions\RemoveProjectAction;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;

new
#[Lazy]
class extends Component
{
    #[Locked]
    public string $currentSlug = '';

    #[Locked]
    public string $projectName = '';

    public string $search = '';

    #[Session('project-picker.sort-by')]
    public string $sortBy = 'recent';

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $projectGroups = [];

    public int $totalProjects = 0;

    public int $matchCount = 0;

    public function mount(): void
    {
        $this->refreshProjects();
    }

    public function updatedSearch(): void
    {
        $this->refreshProjects();
    }

    public function updatedSortBy(): void
    {
        $this->refreshProjects();
    }

    #[On('projects-changed')]
    public function refreshProjects(): void
    {
        $result = app(ListProjectsAction::class)->handle($this->sortBy, $this->search);
        $this->projectGroups = $result->groups;
        $this->totalProjects = $result->total;
        $this->matchCount = $result->matchCount;
    }

    public function selectProject(string $slug): void
    {
        $this->redirect(route('review-page', ['slug' => $slug]));
    }

    public function removeProject(int $projectId): void
    {
        $nextUrl = app(RemoveProjectAction::class)->handle($projectId);

        if ($nextUrl !== null) {
            $this->redirect($nextUrl);

            return;
        }

        $this->refreshProjects();
    }
};
?>

@placeholder
<div class="inline-flex">
    <span class="font-display font-bold tracking-brutal-tight text-base leading-none inline-flex items-center gap-1">
        <span>{{ $projectName ?? '' }}</span>
        <flux:icon icon="chevron-down" variant="outline" class="!size-3 text-gh-muted" />
    </span>
</div>
@endplaceholder

<div
    class="inline-flex"
    x-data="{
        open: false,
        selectedIndex: -1,
        currentSlug: @js($currentSlug),
        toggle() {
            this.open ? this.close() : this.openPanel();
        },
        openPanel() {
            this.open = true;
            this.selectedIndex = -1;
            if ($wire.search !== '') $wire.set('search', '');
            window.dispatchEvent(new CustomEvent('overlay:open', { detail: { name: 'project-picker' } }));
            this.$nextTick(() => this.$refs.searchInput?.focus());
        },
        close() {
            this.open = false;
            this.selectedIndex = -1;
        },
        selectSlug(slug) {
            slug === this.currentSlug ? this.close() : $wire.selectProject(slug);
        },
        rows() {
            return Array.from(this.$refs.rowList?.querySelectorAll('[data-project-picker-row]') ?? []);
        },
        navigate(dir) {
            const rows = this.rows();
            if (!rows.length) return;
            this.selectedIndex = this.selectedIndex < 0
                ? (dir > 0 ? 0 : rows.length - 1)
                : Math.max(0, Math.min(rows.length - 1, this.selectedIndex + dir));
            rows[this.selectedIndex]?.scrollIntoView({ block: 'nearest' });
        },
        openSelected() {
            const rows = this.rows();
            const index = this.selectedIndex >= 0 ? this.selectedIndex : 0;
            const slug = rows[index]?.dataset.slug;
            if (slug) this.selectSlug(slug);
        },
        isHotkey(e) {
            return (e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'k';
        }
    }"
    @project-picker:toggle.window="toggle()"
    @project-picker:close.window="close()"
    @overlay:open.window="if ($event.detail?.name !== 'project-picker') close()"
    @keydown.window="
        if (isHotkey($event)) { $event.preventDefault(); toggle(); return; }
        if (!open) return;
        const inSearch = $event.target === $refs.searchInput;
        if ($event.key === 'Escape') { $event.preventDefault(); close(); return; }
        if ($event.key === 'ArrowDown') { $event.preventDefault(); navigate(1); return; }
        if ($event.key === 'ArrowUp') { $event.preventDefault(); navigate(-1); return; }
        if ($event.key === 'Enter' && inSearch) { $event.preventDefault(); openSelected(); return; }
    "
>
    <x-header-picker-trigger
        tooltip="Switch project · ⌘K"
        aria-label="Switch project (⌘K)"
        variant="display"
        x-on:click="toggle()"
        x-bind:aria-expanded="open"
    >
        <span>{{ $projectName }}</span>
    </x-header-picker-trigger>

    <x-overlay-panel name="project-picker" aria-label="Switch project" size="md">
                <div class="p-3 border-b border-gh-border flex items-center gap-2 shrink-0">
                    <div class="flex-1">
                        <flux:input
                            x-ref="searchInput"
                            wire:model.live.debounce.200ms="search"
                            @input="selectedIndex = -1"
                            placeholder="Switch to project..."
                            icon="magnifying-glass"
                            icon:variant="outline"
                            size="sm"
                            variant="filled"
                        />
                    </div>
                    @php $nextSort = $sortBy === 'recent' ? 'alpha' : 'recent'; @endphp
                    <flux:button
                        variant="ghost"
                        size="sm"
                        wire:click="$set('sortBy', @js($nextSort))"
                        class="text-gh-muted hover:text-gh-text font-mono text-xs shrink-0"
                        tooltip="Toggle sort"
                    >
                        <flux:icon icon="arrows-up-down" variant="outline" class="!size-3.5" />
                        <span>{{ $sortBy === 'recent' ? 'Recent' : 'A–Z' }}</span>
                    </flux:button>
                    @native
                        <livewire:add-project-menu />
                    @endnative
                </div>

                <div class="overflow-y-auto flex-1" x-ref="rowList">
                    @if($matchCount === 0)
                        <div class="px-4 py-10 text-center">
                            <p class="font-mono text-xs text-gh-muted">
                                @if($search !== '')
                                    No matching projects
                                @else
                                    No projects yet
                                @endif
                            </p>
                        </div>
                    @endif

                    @php $rowIndex = 0; @endphp
                    @foreach($projectGroups as $commonDir => $projects)
                    <div wire:key="picker-group-{{ md5($commonDir) }}">
                        @if(count($projects) > 1)
                            <div class="px-3 pt-3 pb-1">
                                <span class="section-label text-gh-muted font-mono truncate block">{{ $commonDir }}</span>
                            </div>
                        @endif

                        @foreach($projects as $project)
                            <div
                                wire:key="picker-project-{{ $project['id'] }}"
                                data-project-picker-row
                                data-slug="{{ $project['slug'] }}"
                                x-data="{ status: null, loaded: false }"
                                x-intersect.once="setTimeout(() => {
                                    fetch('/api/status/{{ $project['id'] }}')
                                        .then(r => r.json())
                                        .then(d => { status = d; loaded = true; })
                                        .catch(() => { loaded = true; });
                                }, {{ $rowIndex * 40 }})"
                                @class([
                                    'group px-3 py-2.5 border-b border-gh-border/50 last:border-b-0 cursor-pointer transition-colors',
                                    'bg-gh-link/5 border-l-2 border-l-gh-link' => $project['slug'] === $currentSlug,
                                ])
                                :class="selectedIndex === {{ $rowIndex }} ? 'bg-gh-text/10' : 'hover:bg-gh-border/30'"
                                @click="selectSlug('{{ $project['slug'] }}')"
                                @mouseenter="selectedIndex = {{ $rowIndex }}"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <span class="shrink-0 w-3.5 flex items-center justify-center">
                                            @if($project['slug'] === $currentSlug)
                                                <flux:icon icon="check" variant="outline" class="!size-3.5 text-gh-link" />
                                            @else
                                                <span class="h-1.5 w-1.5 rounded-full bg-gh-muted/30"></span>
                                            @endif
                                        </span>
                                        <span @class([
                                            'font-semibold tracking-brutal text-sm truncate',
                                            'text-gh-link' => $project['slug'] === $currentSlug,
                                        ])>{{ $project['name'] }}</span>
                                        @if($project['is_worktree'])
                                            <flux:badge size="sm" color="yellow">worktree</flux:badge>
                                        @endif
                                        @if($project['branch'])
                                            <span class="text-[11px] font-mono text-gh-muted px-1.5 py-0.5 rounded border border-gh-border shrink-0 truncate max-w-[140px]">{{ $project['branch'] }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0 text-xs font-mono">
                                        @if($project['comment_count'] > 0)
                                            <span class="flex items-center gap-1 text-gh-link">
                                                <flux:icon icon="chat-bubble-left" variant="outline" class="!size-3" />
                                                {{ $project['comment_count'] }}
                                            </span>
                                        @endif
                                        <span x-show="loaded && status?.dirty" x-cloak>
                                            <span class="text-gh-green" x-text="'+' + (status?.additions || 0)"></span>
                                            <span class="text-gh-red" x-text="'-' + (status?.deletions || 0)"></span>
                                        </span>
                                        <span class="text-gh-muted/70">{{ $project['last_active_ago'] }}</span>
                                        <flux:button
                                            variant="ghost"
                                            size="xs"
                                            icon="trash"
                                            icon:variant="outline"
                                            wire:click.stop="removeProject({{ $project['id'] }})"
                                            wire:confirm="Remove this project from the list?"
                                            class="text-gh-muted hover:text-red-500 opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition-opacity"
                                            tooltip="Remove project"
                                        />
                                    </div>
                                </div>
                                <p class="mt-1 font-mono text-[11px] text-gh-muted/70 truncate pl-6">{{ $project['path'] }}</p>
                            </div>
                            @php $rowIndex++; @endphp
                        @endforeach
                    </div>
                @endforeach
                </div>

                <x-overlay-footer>
                    <x-slot:meta>
                        @if($search !== '')
                            {{ $matchCount }}/{{ $totalProjects }} {{ Str::plural('project', $totalProjects) }}
                        @else
                            {{ $totalProjects }} {{ Str::plural('project', $totalProjects) }}
                        @endif
                    </x-slot:meta>
                </x-overlay-footer>
    </x-overlay-panel>
</div>

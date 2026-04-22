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
            Alpine.store('overlays').open('project-picker');
            this.$nextTick(() => this.$refs.searchInput?.focus());
        },
        close() {
            this.open = false;
            this.selectedIndex = -1;
            if (Alpine.store('overlays').is('project-picker')) Alpine.store('overlays').close();
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
    }"
    x-init="$store.keymap.register('⌘K', () => toggle(), { allowInEditable: true })"
    @project-picker:toggle.window="toggle()"
    @project-picker:close.window="close()"
    x-effect="if (open && !$store.overlays.is('project-picker')) close()"
    @keydown.window="
        if (!open) return;
        const inSearch = $event.target === $refs.searchInput;
        if ($event.key === 'Escape') { $event.preventDefault(); close(); return; }
        if ($event.key === 'ArrowDown') { $event.preventDefault(); navigate(1); return; }
        if ($event.key === 'ArrowUp') { $event.preventDefault(); navigate(-1); return; }
        if ($event.key === 'Enter' && inSearch) { $event.preventDefault(); openSelected(); return; }
    "
>
    <x-header-picker-trigger
        tooltip="Switch repo · ⌘K"
        aria-label="Switch repo (⌘K)"
        variant="display"
        x-on:click="toggle()"
        x-bind:aria-expanded="open"
    >
        <span>{{ $projectName }}</span>
    </x-header-picker-trigger>

    <x-overlay-panel name="project-picker" aria-label="Switch repo" size="md">
                <div class="p-3 border-b border-gh-border flex items-center gap-2 shrink-0">
                    <div class="flex-1">
                        <flux:input
                            x-ref="searchInput"
                            wire:model.live.debounce.200ms="search"
                            @input="selectedIndex = -1"
                            placeholder="Switch to repo..."
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
                    <x-project-list
                        mode="picker"
                        key-prefix="picker"
                        :groups="$projectGroups"
                        :current-slug="$currentSlug"
                        :search="$search"
                        :match-count="$matchCount"
                    />
                </div>

                <x-overlay-footer>
                    <x-slot:meta>
                        @if($search !== '')
                            {{ $matchCount }}/{{ $totalProjects }} {{ Str::plural('repo', $totalProjects) }}
                        @else
                            {{ $totalProjects }} {{ Str::plural('repo', $totalProjects) }}
                        @endif
                    </x-slot:meta>
                </x-overlay-footer>
    </x-overlay-panel>
</div>

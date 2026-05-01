<?php

use App\Actions\ListProjectsAction;
use App\Actions\RemoveProjectAction;
use App\Actions\ResolveStartupRouteAction;
use App\Concerns\InteractsWithRemoteLinks;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;
use Native\Desktop\Facades\Shell;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithRemoteLinks;

    public string $search = '';

    #[Session('select-repo-page.sort-by')]
    public string $sortBy = 'recent';

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $projectGroups = [];

    public int $totalProjects = 0;

    public int $matchCount = 0;

    public string $currentSlug = '';

    public function mount(): void
    {
        $this->currentSlug = app(ResolveStartupRouteAction::class)->lastOpenedSlug() ?? '';
        $this->refreshProjects();

        // Without this, ⌘⇧K from the repo picker would ambush the user with
        // the last project they had open via the menu-handler's cache lookup.
        Cache::forget('rfa.active-project-id');

        if (config('nativephp-internal.running')) {
            \Native\Desktop\Facades\Window::get('main')->title('rfa');
        }
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
        $this->redirect(route('review-page', ['slug' => $slug]), navigate: true);
    }

    public function removeProject(int $projectId): void
    {
        $nextUrl = app(RemoveProjectAction::class)->handle($projectId);

        if ($nextUrl !== null) {
            $this->redirect($nextUrl, navigate: true);

            return;
        }

        $this->refreshProjects();
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

<div class="min-h-screen flex flex-col">
    <div
        class="sticky top-0 z-50"
        x-data
        x-init="
            const update = () => document.documentElement.style.setProperty('--header-h', $el.offsetHeight + 'px');
            update();
            new ResizeObserver(update).observe($el);
        "
    >
        @native
            <livewire:update-banner />
        @endnative

        <header class="bg-gh-bg/80 backdrop-blur-sm border-b border-gh-border px-6 py-4 flex items-center justify-between">
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
    </div>

    <main class="flex-1 flex items-center justify-center px-6 py-10">
        @if($totalProjects === 0)
            <div class="text-center">
                <h1 class="rfa-logo text-7xl text-gh-fg mb-3 tracking-brutal-tight">rfa</h1>
                <p class="font-mono text-sm text-gh-muted mb-10">Be in the loop.</p>
                @native
                    <livewire:add-project-menu variant="expanded" />
                @else
                    <flux:text variant="subtle" size="sm">Run <code class="font-mono bg-gh-border/50 px-1.5 py-0.5 rounded text-xs">rfa</code> from a git repository to get started</flux:text>
                @endnative
            </div>
        @else
            <div class="w-full max-w-2xl">
                <div class="mb-6 text-center">
                    <p class="rfa-logo text-3xl text-gh-muted/40 mb-3" aria-hidden="true">rfa</p>
                    <flux:heading class="font-display tracking-brutal">Pick a repo</flux:heading>
                </div>

                <div class="mb-3 flex items-center gap-2">
                    <div class="flex-1">
                        <flux:input
                            wire:model.live.throttle.50ms="search"
                            placeholder="Search repos..."
                            icon="magnifying-glass"
                            icon:variant="outline"
                            size="sm"
                            variant="filled"
                        />
                    </div>
                    <x-sort-toggle :sort-by="$sortBy" />
                </div>

                <div class="border border-gh-border rounded overflow-hidden bg-gh-surface">
                    <x-project-list
                        mode="page"
                        key-prefix="select"
                        :groups="$projectGroups"
                        :current-slug="$currentSlug"
                        :search="$search"
                        :match-count="$matchCount"
                    />
                </div>

                <div class="mt-3 font-mono text-[11px] text-gh-muted flex items-center justify-between">
                    <span>
                        @if($search !== '')
                            {{ $matchCount }}/{{ $totalProjects }} {{ Str::plural('repo', $totalProjects) }}
                        @else
                            {{ $totalProjects }} {{ Str::plural('repo', $totalProjects) }}
                        @endif
                    </span>
                </div>

                @native
                    <div class="mt-6 flex justify-center">
                        <livewire:add-project-menu variant="expanded" />
                    </div>
                @endnative
            </div>
        @endif
    </main>

    <footer class="py-2 flex items-center justify-center gap-1.5 font-mono text-[11px] text-gh-muted/40">
        <x-external-link href="https://x.com/fgili0" class="hover:text-gh-muted transition-colors">Franco Gilio</x-external-link>
        <span aria-hidden="true">&middot;</span>
        <x-external-link href="https://github.com/fgilio/rfa" class="hover:text-gh-muted transition-colors">PRs welcome</x-external-link>
    </footer>
</div>

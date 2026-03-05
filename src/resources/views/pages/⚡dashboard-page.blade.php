<?php

use App\Actions\ListProjectsAction;
use App\Actions\RemoveProjectAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $projectGroups = [];

    public function mount(): void
    {
        $this->projectGroups = app(ListProjectsAction::class)->handle();
    }

    public function removeProject(int $projectId): void
    {
        app(RemoveProjectAction::class)->handle($projectId);

        $this->projectGroups = app(ListProjectsAction::class)->handle();
    }
};
?>

<div class="min-h-screen">
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
            <h1 class="rfa-logo text-4xl tracking-brutal-tight mb-10">Projects</h1>

            @foreach($projectGroups as $commonDir => $projects)
                <div class="mb-8">
                    @if(count($projects) > 1)
                        <p class="section-label text-gh-muted mb-3 font-mono truncate">{{ $commonDir }}</p>
                    @endif

                    <div class="space-y-3">
                        @foreach($projects as $project)
                            <a href="/p/{{ $project['slug'] }}"
                               class="group block px-5 py-4 rounded-lg border border-gh-border hover:border-gh-text/30 bg-gh-surface transition-all">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <span class="font-semibold tracking-brutal text-base">{{ $project['name'] }}</span>
                                        @if($project['is_worktree'])
                                            <flux:badge size="sm" color="yellow">worktree</flux:badge>
                                        @endif
                                        @if($project['branch'])
                                            <span class="text-xs font-mono text-gh-muted px-2 py-0.5 rounded border border-gh-border">{{ $project['branch'] }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
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
                                <p class="mt-2 font-mono text-xs text-gh-muted truncate">{{ $project['path'] }}</p>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </main>
</div>

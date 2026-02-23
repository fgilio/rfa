<?php

use App\Actions\ListProjectsAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $projectGroups = [];

    public function mount(): void
    {
        $this->projectGroups = app(ListProjectsAction::class)->handle();
    }
};
?>

<div class="min-h-screen">
    <header class="sticky top-0 z-50 bg-gh-surface border-b border-gh-border px-4 py-3 flex items-center justify-between">
        <flux:heading size="lg">rfa</flux:heading>
        <div class="flex items-center gap-3">
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" variant="ghost" size="sm"
                icon="moon" x-show="! $flux.dark" x-cloak />
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" variant="ghost" size="sm"
                icon="sun" x-show="$flux.dark" />
        </div>
    </header>

    <main class="max-w-3xl mx-auto py-12 px-4">
        @if(empty($projectGroups))
            <div class="flex items-center justify-center h-[60vh]">
                <div class="text-center">
                    <flux:icon icon="folder-open" variant="outline" class="mx-auto mb-3 text-gh-muted" />
                    <flux:heading class="mb-2">No projects registered</flux:heading>
                    <flux:text variant="subtle" size="sm">Run <code class="font-mono bg-gh-border/50 px-1.5 py-0.5 rounded">rfa</code> from a git repository to get started</flux:text>
                </div>
            </div>
        @else
            <flux:heading size="lg" class="mb-6">Projects</flux:heading>

            @foreach($projectGroups as $commonDir => $projects)
                <div class="mb-6">
                    @if(count($projects) > 1)
                        <flux:text variant="subtle" size="sm" class="mb-2 font-mono truncate">{{ $commonDir }}</flux:text>
                    @endif

                    <div class="space-y-2">
                        @foreach($projects as $project)
                            <a href="/p/{{ $project['slug'] }}"
                               class="block p-4 rounded-lg border border-gh-border hover:border-gh-accent/50 bg-gh-surface transition-colors">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <flux:heading size="sm">{{ $project['name'] }}</flux:heading>
                                        @if($project['is_worktree'])
                                            <flux:badge size="sm" color="yellow">worktree</flux:badge>
                                        @endif
                                        @if($project['branch'])
                                            <flux:badge size="sm" variant="outline">{{ $project['branch'] }}</flux:badge>
                                        @endif
                                    </div>
                                    <flux:icon icon="chevron-right" variant="micro" class="text-gh-muted shrink-0" />
                                </div>
                                <flux:text variant="subtle" size="sm" class="mt-1 font-mono truncate">{{ $project['path'] }}</flux:text>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </main>
</div>

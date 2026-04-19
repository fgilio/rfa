<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $repoPath = '';

    #[Locked]
    public ?int $projectId = null;

    public bool $open = false;

    public bool $showSubmitted = false;

    public string $filter = '';

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    #[On('comment-updated')]
    #[On('reset-reviewed-files')]
    public function refresh(): void
    {
        // Listening to the pool-wide write events re-renders the drawer so the
        // count badge and list reflect the latest Comment table state.
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\Comment> */
    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = \App\Models\Comment::query()->forProjectOrRepo($this->projectId, $this->repoPath);

        if (! $this->showSubmitted) {
            $query->whereNull('submitted_at');
        }

        return $query;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    #[Computed]
    public function groupedComments(): array
    {
        $query = $this->baseQuery()->orderByDesc('created_at');

        $filter = trim($this->filter);
        if ($filter !== '') {
            $query->where(function ($q) use ($filter) {
                $q->where('file_path', 'like', '%'.$filter.'%')
                    ->orWhere('body', 'like', '%'.$filter.'%');
            });
        }

        return $query->get()
            ->groupBy('file_path')
            ->map(fn ($rows) => $rows->map->toArray()->all())
            ->all();
    }

    #[Computed]
    public function totalCount(): int
    {
        return $this->baseQuery()->count();
    }
}; ?>

<div
    x-data="{ open: @entangle('open').live }"
    class="relative"
>
    <flux:tooltip content="All comments in this repo">
        <flux:button
            variant="ghost"
            size="sm"
            icon="chat-bubble-left-right"
            icon:variant="outline"
            x-on:click="open = !open"
        >
            @if($this->totalCount > 0)
                <span class="font-mono text-[10px] text-gh-muted">{{ $this->totalCount }}</span>
            @endif
        </flux:button>
    </flux:tooltip>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-x-2"
        x-transition:enter-end="opacity-100 translate-x-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 translate-x-0"
        x-transition:leave-end="opacity-0 translate-x-2"
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        class="fixed top-16 right-4 z-[55] w-[380px] max-h-[70vh] bg-gh-bg border border-gh-border rounded-xl shadow-2xl flex flex-col overflow-hidden"
    >
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-gh-border">
            <span class="section-label text-gh-muted">All comments</span>
            <div class="flex items-center gap-2">
                <flux:tooltip :content="$showSubmitted ? 'Hide submitted' : 'Show submitted'">
                    <flux:button
                        variant="ghost"
                        size="xs"
                        :icon="$showSubmitted ? 'archive-box' : 'archive-box-arrow-down'"
                        icon:variant="outline"
                        wire:click="$toggle('showSubmitted')"
                    />
                </flux:tooltip>
                <flux:button
                    variant="ghost"
                    size="xs"
                    icon="x-mark"
                    icon:variant="outline"
                    x-on:click="open = false"
                />
            </div>
        </div>

        <div class="px-3 py-2 border-b border-gh-border">
            <flux:input
                wire:model.live.debounce.200ms="filter"
                placeholder="Filter comments..."
                icon="magnifying-glass"
                icon:variant="outline"
                size="sm"
                variant="filled"
                clearable
            />
        </div>

        <div class="overflow-y-auto flex-1">
            @forelse($this->groupedComments as $filePath => $comments)
                <div class="border-b border-gh-border/50">
                    <div class="px-4 py-2 bg-gh-surface/40 font-mono text-xs text-gh-text truncate">{{ $filePath }}</div>
                    @foreach($comments as $c)
                        <div class="px-4 py-2.5 border-t border-gh-border/30 text-xs">
                            <div class="flex items-center gap-2 text-[10px] font-mono text-gh-muted mb-1">
                                @if(! empty($c['origin_ref']))
                                    <span>{{ $c['origin_ref'] === 'working' ? 'WD' : Str::limit($c['origin_ref'], 7, '') }}</span>
                                @endif
                                @if(! empty($c['start_line']))
                                    <span>&middot;</span>
                                    <span>L{{ $c['start_line'] }}@if(! empty($c['end_line']) && $c['end_line'] !== $c['start_line'])-L{{ $c['end_line'] }}@endif</span>
                                @endif
                                @if(! empty($c['is_draft']))
                                    <span class="ml-auto px-1.5 py-0.5 rounded bg-amber-400/10 text-amber-500 text-[9px]">draft</span>
                                @elseif(! empty($c['submitted_at']))
                                    <span class="ml-auto px-1.5 py-0.5 rounded bg-gh-border/40 text-gh-muted text-[9px]">submitted</span>
                                @endif
                            </div>
                            <div class="text-gh-text whitespace-pre-wrap">{{ $c['body'] }}</div>
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="flex items-center justify-center h-32">
                    <span class="text-xs text-gh-muted">
                        @if(trim($filter) !== '')
                            No matches
                        @else
                            No comments yet
                        @endif
                    </span>
                </div>
            @endforelse
        </div>
    </div>
</div>

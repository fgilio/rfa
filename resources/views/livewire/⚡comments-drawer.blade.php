<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new
#[Lazy]
class extends Component
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
        // A no-op body is enough: Livewire re-renders the component on #[On] hits,
        // which invalidates the computed properties so the count badge picks up
        // pool changes. The list itself is gated on $open, so closed drawers
        // skip the expensive query.
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
        if (! $this->open) {
            return [];
        }

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

@placeholder
{{-- Keep the trigger button visible at first paint so the header layout doesn't
     shift; the real component hydrates and supplies the count + drawer body. --}}
<div class="relative">
    <flux:tooltip content="All comments · ⌘J">
        <flux:button variant="ghost" size="sm" icon="chat-bubble-left-right" icon:variant="outline" aria-label="All comments in this repo" />
    </flux:tooltip>
</div>
@endplaceholder

<div
    x-data="{
        open: @entangle('open').live,
        isHotkey(e) { return (e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'j'; },
        openPanel() {
            this.open = true;
            window.dispatchEvent(new CustomEvent('overlay:open', { detail: { name: 'comments-drawer' } }));
            this.$nextTick(() => this.$refs.searchInput?.focus());
        },
        close() { this.open = false; },
        toggle() { this.open ? this.close() : this.openPanel(); },
    }"
    @keydown.window="
        if (isHotkey($event)) {
            const inEditable = $event.target.tagName === 'TEXTAREA' || $event.target.tagName === 'INPUT' || $event.target.isContentEditable;
            if (!inEditable) { $event.preventDefault(); toggle(); return; }
        }
        if (open && $event.key === 'Escape') { $event.preventDefault(); close(); return; }
    "
    @overlay:open.window="if ($event.detail?.name !== 'comments-drawer') close()"
    class="relative"
>
    <flux:tooltip content="All comments · ⌘J">
        <flux:button
            variant="ghost"
            size="sm"
            icon="chat-bubble-left-right"
            icon:variant="outline"
            aria-label="All comments in this repo"
            x-on:click="toggle()"
        >
            @if($this->totalCount > 0)
                <span class="font-mono text-[10px] text-gh-muted">{{ $this->totalCount }}</span>
            @endif
        </flux:button>
    </flux:tooltip>

    <x-overlay-panel name="comments-drawer" aria-label="All comments" size="md" on-close="close()">
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-gh-border shrink-0">
            <span class="section-label text-gh-muted">All comments</span>
            <div class="flex items-center gap-2">
                <flux:tooltip :content="$showSubmitted ? 'Hide submitted' : 'Show submitted'">
                    <flux:button
                        variant="ghost"
                        size="xs"
                        :icon="$showSubmitted ? 'archive-box' : 'archive-box-arrow-down'"
                        icon:variant="outline"
                        :aria-label="$showSubmitted ? 'Hide submitted comments' : 'Show submitted comments'"
                        wire:click="$toggle('showSubmitted')"
                    />
                </flux:tooltip>
                <flux:button
                    variant="ghost"
                    size="xs"
                    icon="x-mark"
                    icon:variant="outline"
                    aria-label="Close comments drawer"
                    x-on:click="close()"
                />
            </div>
        </div>

        <div class="px-3 py-2 border-b border-gh-border shrink-0">
            <flux:input
                x-ref="searchInput"
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
                                    <span class="ml-auto px-1.5 py-0.5 rounded bg-gh-draft/10 text-gh-draft text-[9px]">draft</span>
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

        <x-overlay-footer>
            <x-slot:meta>
                {{ $this->totalCount }} {{ Str::plural('comment', $this->totalCount) }}
            </x-slot:meta>
        </x-overlay-footer>
    </x-overlay-panel>
</div>

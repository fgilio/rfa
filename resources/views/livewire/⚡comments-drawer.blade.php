<?php

use App\Actions\LoadCommentsDrawerAction;
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

    /** @return array{groupedComments: array<string, list<array<string, mixed>>>, totalCount: int} */
    #[Computed]
    public function drawerData(): array
    {
        return app(LoadCommentsDrawerAction::class)->handle(
            repoPath: $this->repoPath,
            projectId: $this->projectId,
            showSubmitted: $this->showSubmitted,
            filter: $this->filter,
            includeRows: $this->open,
        );
    }

    /** @return array<string, list<array<string, mixed>>> */
    #[Computed]
    public function groupedComments(): array
    {
        return $this->drawerData['groupedComments'];
    }

    #[Computed]
    public function totalCount(): int
    {
        return $this->drawerData['totalCount'];
    }
}; ?>

@placeholder
{{-- Keep the trigger button visible at first paint so the header layout doesn't
     shift; the real component hydrates and supplies the count + drawer body. --}}
<div class="relative">
    <flux:tooltip content="All comments · {{ \App\Support\Shortcuts::display('comments-drawer.toggle') }}">
        <flux:button variant="ghost" size="sm" icon="chat-bubble-left-right" icon:variant="outline" aria-label="All comments in this repo" />
    </flux:tooltip>
</div>
@endplaceholder

<div
    x-data="{
        open: @entangle('open').live,
        openPanel() {
            this.open = true;
            Alpine.store('overlays').open('comments-drawer');
            this.$nextTick(() => this.$refs.searchInput?.focus());
        },
        close() {
            this.open = false;
            if (Alpine.store('overlays').is('comments-drawer')) Alpine.store('overlays').close();
        },
        toggle() { this.open ? this.close() : this.openPanel(); },
        select(commentId, filePath) {
            this.$dispatch('scroll-to-comment', { commentId, filePath });
            this.close();
        },
    }"
    x-init="$store.shortcuts.register('comments-drawer.toggle', () => toggle())"
    @keydown.window="if (open && $event.key === 'Escape') { $event.preventDefault(); close(); return; }"
    @comment-thread-updated.window="if (open) $wire.$refresh()"
    x-effect="if (open && !$store.overlays.is('comments-drawer')) close()"
    class="relative"
>
    {{-- Plain :aria-expanded / x-bind:aria-expanded on a Flux component is
         pre-compiled by Flux's blaze pass as a PHP expression and blows up
         server-side. The trigger doesn't strictly need a reactive
         aria-expanded — a static aria-haspopup is sufficient for screen
         readers to announce that this button opens a dialog. --}}
    <flux:tooltip content="All comments · {{ \App\Support\Shortcuts::display('comments-drawer.toggle') }}">
        <flux:button
            variant="ghost"
            size="sm"
            icon="chat-bubble-left-right"
            icon:variant="outline"
            aria-label="All comments in this repo"
            aria-haspopup="dialog"
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
                    <div class="px-4 py-2 bg-gh-surface/40 text-xs text-gh-text">
                        <x-file-path :path="$filePath" />
                    </div>
                    @foreach($comments as $c)
                        <div
                            x-data="{ expanded: @js($c['isReplyFilterMatch'] ?? false) }"
                            wire:key="drawer-comment-{{ $c['id'] }}"
                            data-testid="drawer-comment-{{ $c['id'] }}"
                            class="group border-t border-gh-border/30 text-xs hover:bg-gh-surface/40"
                        >
                            <div class="relative">
                                <button
                                    type="button"
                                    class="block w-full px-4 py-2.5 text-left focus-visible:bg-gh-surface/60 focus-visible:outline focus-visible:outline-1 focus-visible:outline-gh-accent focus-visible:-outline-offset-1"
                                    x-on:click="@js(! empty($c['submittedAt'])) ? expanded = !expanded : select(@js($c['id']), @js($c['file']))"
                                >
                                    <div class="flex items-center gap-2 text-[10px] font-mono text-gh-muted mb-1 pr-7">
                                        @if(! empty($c['originRef']))
                                            <span>{{ match($c['originRef']) { 'working' => 'WD', 'external' => 'EXT', default => Str::limit($c['originRef'], 7, '') } }}</span>
                                        @endif
                                        @if(! empty($c['startLine']))
                                            <span aria-hidden="true">&middot;</span>
                                            <span>L{{ $c['startLine'] }}@if(! empty($c['endLine']) && $c['endLine'] !== $c['startLine'])-L{{ $c['endLine'] }}@endif</span>
                                        @endif
                                        <div class="ml-auto flex items-center gap-1">
                                            @if(! empty($c['isDraft']))
                                                <span class="px-1.5 py-0.5 rounded bg-gh-draft/10 text-gh-draft text-[9px]">draft</span>
                                            @elseif(! empty($c['submittedAt']))
                                                <span class="px-1.5 py-0.5 rounded bg-gh-border/40 text-gh-muted text-[9px]">submitted</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="text-gh-text whitespace-pre-wrap">{{ $c['body'] }}</div>
                                </button>
                                <div class="absolute right-4 top-2">
                                    <flux:tooltip content="Copy comment">
                                        <flux:button
                                            icon="clipboard-document"
                                            icon:variant="outline"
                                            variant="ghost"
                                            size="xs"
                                            aria-label="Copy comment"
                                            x-on:click.stop="$dispatch('copy-to-clipboard', { text: @js($c['body']), toast: 'Copied' })"
                                            class="opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity hover:!text-gh-accent"
                                        />
                                    </flux:tooltip>
                                </div>
                            </div>
                            @if(count($c['replies']) > 0)
                                <button
                                    type="button"
                                    class="mx-4 mb-2 text-[10px] font-mono text-gh-muted hover:text-gh-accent"
                                    x-on:click="expanded = !expanded"
                                    x-bind:aria-expanded="expanded"
                                >
                                    {{ count($c['replies']) }} {{ Str::plural('reply', count($c['replies'])) }}
                                </button>
                                <div x-show="expanded" x-cloak class="cursor-default px-4 pb-2.5">
                                    <x-comment-replies :comment="$c" />
                                </div>
                            @else
                                <div class="px-4 pb-2.5">
                                    <x-comment-replies :comment="$c" />
                                </div>
                            @endif
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

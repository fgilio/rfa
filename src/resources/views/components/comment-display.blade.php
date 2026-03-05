{{-- Parent Alpine scope contract: editDraft() (for draft comments), $wire (for delete-comment dispatch) --}}
@props(['comment', 'borderClass' => 'border-y'])

@php $isDraft = $comment['isDraft'] ?? false; @endphp

<div
    class="{{ $isDraft ? 'draft-indicator' : 'comment-indicator' }} bg-gh-surface/80 {{ $borderClass }} border-gh-border px-4 py-2 {{ $isDraft ? 'cursor-pointer' : '' }}"
    @if($isDraft)
        x-on:click="editDraft(@js($comment))"
        data-testid="draft-comment"
    @endif
>
    <div class="flex items-start justify-between gap-2">
        <div class="flex items-center gap-2">
            @if($isDraft)
                <span class="text-[10px] font-mono font-medium text-amber-500 dark:text-amber-400 uppercase tracking-wider">Draft</span>
            @endif
            <flux:text size="sm" class="whitespace-pre-wrap">{{ $comment['body'] }}</flux:text>
        </div>
        <flux:tooltip content="Delete comment">
            <flux:button
                icon="x-mark"
                icon:variant="outline"
                variant="ghost"
                size="xs"
                aria-label="Delete comment"
                x-on:click.stop="$wire.dispatch('delete-comment', { commentId: '{{ $comment['id'] }}' })"
                class="shrink-0 hover:!text-red-400"
            />
        </flux:tooltip>
    </div>
    @if(($comment['startLine'] ?? null) !== ($comment['endLine'] ?? null) && ($comment['endLine'] ?? null) !== null)
        <flux:text variant="subtle" size="sm" class="!text-[10px] mt-1">Lines {{ $comment['startLine'] }}-{{ $comment['endLine'] }}</flux:text>
    @endif
</div>

{{-- Parent Alpine scope contract: editComment(), $wire (for delete-comment dispatch) --}}
@props(['comment', 'borderClass' => 'border-y'])

@php
    $isDraft = $comment['isDraft'] ?? false;
    $startLine = $comment['startLine'] ?? null;
    $endLine = $comment['endLine'] ?? null;
@endphp

<div
    class="group {{ $isDraft ? 'draft-indicator' : 'comment-indicator' }} bg-gh-surface/80 {{ $borderClass }} border-gh-border px-4 py-2"
    @if($isDraft) data-testid="draft-comment" @endif
>
    <div class="flex items-start justify-between gap-2">
        <div class="flex items-center gap-2 min-w-0">
            @if($isDraft)
                <span class="text-[10px] font-mono font-medium text-gh-draft uppercase tracking-wider">Draft</span>
            @endif
            <flux:text size="sm" class="whitespace-pre-wrap">{{ $comment['body'] }}</flux:text>
        </div>
        <div class="flex items-center gap-0.5 shrink-0 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
            <flux:tooltip content="Copy comment">
                <flux:button
                    icon="clipboard-document"
                    icon:variant="outline"
                    variant="ghost"
                    size="xs"
                    aria-label="Copy comment"
                    x-on:click.stop="$dispatch('copy-to-clipboard', { text: @js($comment['body']), toast: 'Copied' })"
                    class="hover:!text-gh-accent"
                    data-testid="copy-comment"
                />
            </flux:tooltip>
            <flux:tooltip content="Edit comment">
                <flux:button
                    icon="pencil-square"
                    icon:variant="outline"
                    variant="ghost"
                    size="xs"
                    aria-label="Edit comment"
                    x-on:click.stop="editComment(@js($comment))"
                    class="hover:!text-gh-accent"
                    data-testid="edit-comment"
                />
            </flux:tooltip>
            <flux:tooltip content="Delete comment">
                <flux:button
                    icon="x-mark"
                    icon:variant="outline"
                    variant="ghost"
                    size="xs"
                    aria-label="Delete comment"
                    x-on:click.stop="$wire.dispatch('delete-comment', { commentId: '{{ $comment['id'] }}' })"
                    class="hover:!text-gh-red"
                />
            </flux:tooltip>
        </div>
    </div>
    @if($startLine !== null)
        <flux:text variant="subtle" size="sm" class="!text-[10px] mt-1">
            @if($endLine !== null && $endLine !== $startLine)
                Lines {{ $startLine }}-{{ $endLine }}
            @else
                Line {{ $startLine }}
            @endif
        </flux:text>
    @endif
    <x-comment-replies :comment="$comment" />
</div>

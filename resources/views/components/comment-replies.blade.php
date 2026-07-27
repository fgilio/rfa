@props(['comment'])

@php
    $replies = $comment['replies'] ?? [];
@endphp

<div
    x-data="commentThread({ commentId: @js($comment['id']) })"
    class="mt-2 border-l-2 border-gh-border/70 pl-3"
    data-testid="comment-thread"
>
    @if($replies !== [])
        <div class="space-y-2">
            @foreach($replies as $reply)
                @php
                    $isHumanReply = ($reply['authorType'] ?? null) === 'human'
                        && ($reply['authorKey'] ?? null) === 'rfa-ui';
                    $author = $isHumanReply
                        ? 'You'
                        : ($reply['authorLabel'] ?? $reply['authorKey'] ?? 'Agent');
                    $wasEdited = ! empty($reply['createdAt'])
                        && ! empty($reply['updatedAt'])
                        && $reply['createdAt'] !== $reply['updatedAt'];
                @endphp

                <div
                    class="group/reply"
                    data-testid="comment-reply"
                    x-show="editingReplyId !== @js($reply['id'])"
                    wire:key="comment-reply-{{ $reply['id'] }}"
                >
                    <div class="flex items-center gap-1.5 text-[10px] font-mono text-gh-muted">
                        <span class="font-medium text-gh-text">{{ $author }}</span>
                        @if(! empty($reply['createdAt']))
                            <span aria-hidden="true">&middot;</span>
                            <time datetime="{{ $reply['createdAt'] }}">{{ \Illuminate\Support\Carbon::parse($reply['createdAt'])->diffForHumans(short: true) }}</time>
                        @endif
                        @if($wasEdited)
                            <span aria-label="Edited">(edited)</span>
                        @endif
                        <div class="ml-auto flex items-center gap-0.5 opacity-0 group-hover/reply:opacity-100 focus-within:opacity-100 transition-opacity">
                            <flux:tooltip content="Copy reply">
                                <flux:button
                                    icon="clipboard-document"
                                    icon:variant="outline"
                                    variant="ghost"
                                    size="xs"
                                    aria-label="Copy reply"
                                    x-on:click.stop="$dispatch('copy-to-clipboard', { text: @js($reply['body']), toast: 'Copied' })"
                                />
                            </flux:tooltip>
                            @if($isHumanReply)
                                <flux:tooltip content="Edit reply">
                                    <flux:button
                                        icon="pencil-square"
                                        icon:variant="outline"
                                        variant="ghost"
                                        size="xs"
                                        aria-label="Edit reply"
                                        x-on:click.stop="edit(@js($reply))"
                                    />
                                </flux:tooltip>
                                <flux:tooltip content="Delete reply">
                                    <flux:button
                                        icon="x-mark"
                                        icon:variant="outline"
                                        variant="ghost"
                                        size="xs"
                                        aria-label="Delete reply"
                                        x-on:click.stop="remove(@js($reply['id']))"
                                        class="hover:!text-gh-red"
                                    />
                                </flux:tooltip>
                            @endif
                        </div>
                    </div>
                    <div class="mt-0.5 text-xs text-gh-text whitespace-pre-wrap">{{ $reply['body'] }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div x-show="replying || editingReplyId !== null" x-cloak class="mt-2" data-comment-form>
        <flux:textarea
            x-ref="replyInput"
            x-model="body"
            x-on:keydown.escape.stop="cancel()"
            placeholder="Write a reply... ({{ \App\Support\Shortcuts::display('comment.save') }} to save, Esc to cancel)"
            rows="auto"
            resize="none"
            class="font-mono text-xs"
        />
        <div class="flex justify-end gap-2 mt-2">
            <flux:button variant="ghost" size="xs" x-on:click="cancel()">Cancel</flux:button>
            <flux:button
                variant="primary"
                size="xs"
                data-comment-save
                x-on:click="submit()"
                x-bind:disabled="!body.trim()"
            >
                Save
            </flux:button>
        </div>
    </div>

    <flux:button
        x-show="!replying && editingReplyId === null"
        variant="ghost"
        size="xs"
        icon="arrow-uturn-left"
        icon:variant="outline"
        x-on:click.stop="reply()"
        class="mt-1.5"
        data-testid="reply-to-comment"
    >
        Reply
    </flux:button>
</div>

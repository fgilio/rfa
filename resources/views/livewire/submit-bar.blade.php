{{-- Fixed bottom submit bar --}}
<div class="fixed bottom-0 left-0 right-0 z-50 bg-gh-bg/80 backdrop-blur-sm border-t border-gh-border">
    @if($submitted)
        <div class="px-5 py-3.5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <flux:icon icon="check-circle" variant="outline" class="text-gh-green shrink-0" />
                <span class="font-semibold tracking-brutal shrink-0">Review submitted</span>
                <span class="font-mono text-xs text-gh-muted px-2 py-0.5 rounded border border-gh-border truncate">{{ $exportResult }}</span>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <span class="font-mono text-[11px] text-gh-muted">Copied to clipboard</span>
                <flux:button
                    size="sm"
                    icon="clipboard-document"
                    icon:variant="outline"
                    @click="$dispatch('copy-to-clipboard', { text: @js($exportResult), toast: 'Copied again' })"
                >
                    Copy again
                </flux:button>
                <flux:button
                    variant="primary"
                    size="sm"
                    icon="pencil-square"
                    icon:variant="outline"
                    wire:click="startNewReview"
                >
                    Start a new review
                </flux:button>
            </div>
        </div>
    @else
        <div class="px-5 py-3.5 flex items-center gap-4"
            x-data="{
                get commentCount() { return $wire.comments.filter(c => !c.isDraft).length },
                get draftCount() { return $wire.comments.filter(c => c.isDraft).length },
                get hasGlobal() { return ($wire.globalComment || '').trim().length > 0 }
            }"
        >
            <div class="flex-1">
                <flux:textarea
                    wire:model.live.debounce.500ms="globalComment"
                    placeholder="Overall review comment (optional)"
                    rows="auto"
                    resize="none"
                    class="font-mono text-xs"
                />
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <template x-if="commentCount > 0">
                    <span class="font-mono text-xs text-gh-muted" x-text="commentCount + ' ' + (commentCount === 1 ? 'comment' : 'comments')"></span>
                </template>
                <template x-if="draftCount > 0">
                    <span class="font-mono text-xs text-gh-draft" x-text="draftCount + ' ' + (draftCount === 1 ? 'draft' : 'drafts')"></span>
                </template>
                <template x-if="commentCount + draftCount > 0">
                    <div class="flex items-center gap-3">
                        <flux:tooltip content="Clear all comments">
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                icon:variant="outline"
                                @click="
                                    let parts = [];
                                    if (commentCount > 0) parts.push(commentCount + ' comment' + (commentCount === 1 ? '' : 's'));
                                    if (draftCount > 0) parts.push(draftCount + ' draft' + (draftCount === 1 ? '' : 's'));
                                    if (confirm('Clear ' + parts.join(' and ') + '?')) $wire.clearAllComments()
                                "
                                class="hover:!text-red-400"
                            />
                        </flux:tooltip>
                        <span class="w-px h-4 bg-gh-border"></span>
                    </div>
                </template>
                <flux:button
                    variant="primary"
                    @click="if (draftCount > 0 && !confirm(`You have ${draftCount} draft comment${draftCount === 1 ? '' : 's'} that won't be included. Submit anyway?`)) return; $wire.submitReview()"
                    x-bind:disabled="commentCount === 0 && !hasGlobal"
                >
                    Submit Review
                </flux:button>
            </div>
        </div>
    @endif
</div>

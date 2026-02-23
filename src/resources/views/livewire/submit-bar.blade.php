{{-- Fixed bottom submit bar --}}
<div class="fixed bottom-0 left-0 right-0 z-50 bg-gh-surface border-t border-gh-border">
    @if($submitted)
        <div class="px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <flux:icon icon="check-circle" variant="mini" class="text-gh-green" />
                <flux:heading size="sm">Review submitted</flux:heading>
                <flux:badge class="font-mono">{{ $exportResult }}</flux:badge>
            </div>
            <flux:text variant="subtle" size="sm">Copied to clipboard - Ctrl+C to exit</flux:text>
        </div>
    @else
        <div class="px-4 py-3 flex items-center gap-3"
            x-data="{ get commentCount() { return $wire.comments.length }, get hasGlobal() { return ($wire.globalComment || '').trim().length > 0 } }"
        >
            <div class="flex-1">
                <flux:textarea
                    wire:model.blur="globalComment"
                    placeholder="Overall review comment (optional)"
                    rows="1"
                    resize="none"
                    class="font-mono text-xs"
                />
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <template x-if="commentCount > 0">
                    <flux:badge x-text="commentCount + ' ' + (commentCount === 1 ? 'comment' : 'comments')" />
                </template>
                <flux:button
                    variant="primary"
                    wire:click="submitReview"
                    x-bind:disabled="commentCount === 0 && !hasGlobal"
                >
                    Submit Review
                </flux:button>
            </div>
        </div>
    @endif
</div>

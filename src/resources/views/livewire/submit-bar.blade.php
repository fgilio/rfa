{{-- Fixed bottom submit bar --}}
<div class="fixed bottom-0 left-0 right-0 z-50 bg-gh-surface border-t border-gh-border">
    @if($submitted)
        <div class="px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span class="text-sm text-gh-text">Review submitted</span>
                <code class="text-xs text-gh-muted bg-gh-bg px-2 py-0.5 rounded">{{ $exportResult }}</code>
            </div>
            <span class="text-xs text-gh-muted">Copied to clipboard - Ctrl+C to exit</span>
        </div>
    @else
        <div class="px-4 py-3 flex items-center gap-3">
            <div class="flex-1">
                <textarea
                    wire:model="globalComment"
                    class="w-full bg-gh-bg border border-gh-border rounded px-3 py-2 text-gh-text text-xs font-mono resize-none h-[38px] focus:outline-none focus:border-gh-accent"
                    placeholder="Overall review comment (optional)"
                ></textarea>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                @if(count($comments) > 0)
                    <span class="text-xs text-gh-muted">
                        {{ count($comments) }} {{ Str::plural('comment', count($comments)) }}
                    </span>
                @endif
                <button
                    wire:click="submitReview"
                    @class([
                        'px-4 py-2 text-sm font-medium rounded transition-colors',
                        'bg-green-700 hover:bg-green-600 text-white' => count($comments) > 0 || trim($globalComment) !== '',
                        'bg-gh-border text-gh-muted cursor-not-allowed' => count($comments) === 0 && trim($globalComment) === '',
                    ])
                    @if(count($comments) === 0 && trim($globalComment) === '')
                        disabled
                    @endif
                >
                    Submit Review
                </button>
            </div>
        </div>
    @endif
</div>

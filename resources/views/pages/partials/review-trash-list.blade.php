{{-- Trash list for the review page: discarded working-tree files with an expiry countdown, restore, and permanent-delete. Rendered inside the page, so $wire resolves against it. --}}

@if(! empty($trashedFiles))
    <div class="border-t border-gh-border mt-3 pt-3">
        <span class="section-label text-gh-muted mb-3 block">Trash</span>
        @foreach($trashedFiles as $trashed)
            <div class="w-full px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2 group transition-colors"
                x-data="{
                    expiresAt: {{ $trashed['expires_at'] ? \Carbon\Carbon::parse($trashed['expires_at'])->getTimestampMs() : 0 }},
                    remaining: '',
                    intervalId: null,
                    init() {
                        const update = () => {
                            const ms = this.expiresAt - Date.now();
                            if (ms <= 0) { this.remaining = 'expired'; clearInterval(this.intervalId); return; }
                            const m = Math.ceil(ms / 60000);
                            this.remaining = m < 1 ? '< 1m' : m + 'm';
                        };
                        update();
                        this.intervalId = setInterval(update, 15000);
                    },
                    destroy() {
                        clearInterval(this.intervalId);
                    },
                }"
            >
                <span class="font-mono text-xs text-gh-muted truncate flex-1" title="{{ $trashed['file_path'] }}">{{ basename($trashed['file_path']) }}</span>
                <span class="text-[10px] text-gh-muted tabular-nums" x-text="remaining"></span>
                <button @click="$wire.restoreDiscardedFile({{ $trashed['id'] }})" title="Restore"
                    aria-label="Restore discarded file"
                    class="opacity-0 group-hover:opacity-100 transition-opacity text-gh-green hover:text-gh-text shrink-0">
                    <flux:icon icon="arrow-uturn-left" variant="outline" class="!size-3.5" />
                </button>
                <x-arm-commit-button
                    icon="trash"
                    tooltip="Permanently delete"
                    @confirmed="$wire.permanentlyDeleteTrashed({{ $trashed['id'] }})"
                    class="opacity-0 group-hover:opacity-100 transition-opacity shrink-0"
                />
            </div>
        @endforeach
    </div>
@endif

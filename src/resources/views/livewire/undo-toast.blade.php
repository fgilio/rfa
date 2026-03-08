{{-- Undo toast for comment deletions --}}
<div
    x-data="{
        visible: false,
        message: '',
        payload: null,
        remaining: 0,
        intervalId: null,

        show(detail) {
            this.dismiss();
            this.payload = detail.payload;
            this.message = detail.type === 'clear-all'
                ? `Cleared ${detail.payload.length} comment${detail.payload.length === 1 ? '' : 's'}`
                : 'Comment deleted';
            this.remaining = 10;
            this.visible = true;
            this.intervalId = setInterval(() => {
                this.remaining--;
                if (this.remaining <= 0) this.dismiss();
            }, 1000);
        },

        undo() {
            if (!this.payload) return;
            $wire.restoreComments(this.payload);
            this.dismiss();
        },

        dismiss() {
            if (this.intervalId) { clearInterval(this.intervalId); this.intervalId = null; }
            this.visible = false;
            this.payload = null;
        },

        destroy() {
            if (this.intervalId) clearInterval(this.intervalId);
        }
    }"
    @undo-available.window="show($event.detail)"
    @keydown.window="
        if (!visible || !payload) return;
        if ($event.target.tagName === 'TEXTAREA' || $event.target.tagName === 'INPUT') return;
        if (($event.metaKey || $event.ctrlKey) && $event.key === 'z') { undo(); $event.preventDefault(); }
    "
    x-show="visible"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2"
    role="status"
    aria-live="polite"
    data-testid="undo-toast"
    class="fixed bottom-20 left-1/2 -translate-x-1/2 z-50"
>
    <div class="bg-gh-surface border border-gh-border rounded px-4 py-2.5 font-mono text-xs flex items-center gap-3 shadow-lg">
        <span x-text="message" class="text-gh-text"></span>
        <button @click="undo()" class="text-gh-link hover:underline font-medium" data-testid="undo-button">Undo</button>
        <span class="text-gh-muted" x-text="remaining + 's'"></span>
        {{-- Progress bar --}}
        <div class="w-16 h-0.5 bg-gh-border rounded-full overflow-hidden">
            <div class="h-full bg-gh-muted transition-all duration-1000 ease-linear rounded-full"
                :style="{ width: (remaining / 10 * 100) + '%' }"></div>
        </div>
        <button @click="dismiss()" class="text-gh-muted hover:text-gh-text" aria-label="Dismiss">
            <flux:icon icon="x-mark" variant="outline" class="!size-3.5" />
        </button>
    </div>
</div>

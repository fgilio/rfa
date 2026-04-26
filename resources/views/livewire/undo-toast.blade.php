{{-- Generic undo toast with LIFO stack --}}
<div
    x-data="{
        stack: [],
        intervalId: null,
        coalesceWindowMs: 3000,

        get current() { return this.stack[0] ?? null },
        get visible() { return this.current !== null },
        get remaining() {
            if (!this.current) return 0;
            return Math.max(0, Math.ceil((this.current.expiresAt - Date.now()) / 1000));
        },

        push(detail) {
            // Coalesce: bursts of same-type 'mark-reviewed' within 3s merge into one
            // toast so power users marking many files don't see a wall of toasts.
            const top = this.stack[0];
            const ttl = detail.ttl ?? 10;
            if (
                detail.type === 'mark-reviewed'
                && top && top.type === 'mark-reviewed'
                && (Date.now() - top.createdAt) < this.coalesceWindowMs
                && Array.isArray(detail.payload?.filePaths)
                && Array.isArray(top.payload?.filePaths)
            ) {
                // Dedupe so the displayed count never inflates when the same file
                // is marked → un-marked → marked again within the coalesce window.
                top.payload.filePaths = Array.from(new Set([
                    ...top.payload.filePaths,
                    ...detail.payload.filePaths,
                ]));
                top.createdAt = Date.now();
                top.expiresAt = Date.now() + ttl * 1000;
                const n = top.payload.filePaths.length;
                top.message = n === 1
                    ? '1 file marked as reviewed'
                    : n + ' files marked as reviewed';
                return;
            }
            this.stack.unshift({
                type: detail.type,
                payload: detail.payload,
                message: detail.message ?? 'Action completed',
                createdAt: Date.now(),
                expiresAt: Date.now() + ttl * 1000,
            });
            this.startTicker();
        },

        undo() {
            const entry = this.stack.shift();
            if (!entry) return;
            $wire.undo(entry.type, entry.payload);
        },

        dismiss() {
            this.stack.shift();
            if (!this.stack.length) this.stopTicker();
        },

        tick() {
            const now = Date.now();
            this.stack = this.stack.filter(e => e.expiresAt > now);
            if (!this.stack.length) this.stopTicker();
        },

        startTicker() {
            if (this.intervalId) return;
            this.intervalId = setInterval(() => this.tick(), 1000);
        },
        stopTicker() {
            if (this.intervalId) { clearInterval(this.intervalId); this.intervalId = null; }
        },
        destroy() { this.stopTicker(); }
    }"
    @undo-available.window="push($event.detail)"
    @keydown.window="
        if (!visible || !current) return;
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
    class="fixed bottom-20 left-1/2 -translate-x-1/2 z-50 max-w-sm"
>
    {{-- Visual language matches flux:toast: rounded-xl, shadow-lg, surface bg + border --}}
    <div class="p-2 flex rounded-xl shadow-lg bg-gh-surface border border-gh-border"
        :class="current?.type === 'discard' && 'border-l-2 border-l-gh-accent'">
        <div class="flex-1 flex items-center gap-3 py-1.5 px-2.5 text-sm">
            <span x-text="current?.message" class="font-medium text-gh-text"></span>
            <button
                @click="undo()"
                class="font-medium text-gh-link hover:underline shrink-0"
                data-testid="undo-button"
            >Undo</button>
            <template x-if="current?.type === 'discard'">
                <button
                    @click="dismiss()"
                    class="font-medium text-gh-muted hover:text-gh-text shrink-0"
                    data-testid="undo-ok-button"
                >OK</button>
            </template>
            <span class="font-mono text-xs text-gh-muted tabular-nums shrink-0" x-text="remaining + 's'"></span>
        </div>
        <button
            @click="dismiss()"
            class="inline-flex items-center justify-center rounded-md size-8 text-gh-muted hover:text-gh-text hover:bg-gh-hover-bg shrink-0"
            aria-label="Dismiss"
            x-show="current?.type !== 'discard'"
        >
            <flux:icon icon="x-mark" variant="outline" class="!size-4" />
        </button>
    </div>
</div>

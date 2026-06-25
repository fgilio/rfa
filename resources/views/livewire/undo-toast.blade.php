{{-- Generic undo toast with LIFO stack --}}
<div
    x-data="{
        stack: [],
        intervalId: null,

        get current() { return this.stack[0] ?? null },
        get visible() { return this.current !== null },
        get remaining() {
            if (!this.current) return 0;
            return Math.max(0, Math.ceil((this.current.expiresAt - Date.now()) / 1000));
        },

        push(detail) {
            const ttl = detail.ttl ?? 10;
            this.stack.unshift({
                type: detail.type,
                payload: detail.payload,
                message: detail.message ?? 'Action completed',
                createdAt: Date.now(),
                ttl,
                expiresAt: Date.now() + ttl * 1000,
            });
            this.startTicker();
        },

        undo() {
            const entry = this.stack.shift();
            if (!entry) return;
            $wire.undo(entry.type, entry.payload);
            this.refreshTopExpiry();
        },

        dismiss() {
            this.stack.shift();
            this.refreshTopExpiry();
            if (!this.stack.length) this.stopTicker();
        },

        tick() {
            if (!this.current) { this.stopTicker(); return; }
            if (this.current.expiresAt > Date.now()) return;
            this.stack.shift();
            this.refreshTopExpiry();
            if (!this.stack.length) this.stopTicker();
        },

        // Only the visible (topmost) entry counts down. When it leaves the stack,
        // the next entry surfaces with a fresh TTL so older undos don't silently
        // expire while a newer one was on screen.
        refreshTopExpiry() {
            const top = this.stack[0];
            if (!top) return;
            top.expiresAt = Date.now() + top.ttl * 1000;
        },

        startTicker() {
            if (this.intervalId) return;
            this.intervalId = setInterval(() => this.tick(), 1000);
        },
        stopTicker() {
            if (this.intervalId) { clearInterval(this.intervalId); this.intervalId = null; }
        },
        init() {
            // allowInEditable is false in the catalog, so the keymap store already
            // suppresses ⌘Z while focus is in a textarea/input.
            this.$store.shortcuts.register('review.undo', () => {
                if (this.visible && this.current) this.undo();
            });
        },
        destroy() {
            this.stopTicker();
            this.$store.shortcuts.unregister('review.undo');
        }
    }"
    @undo-available.window="push($event.detail)"
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
    class="fixed bottom-20 left-1/2 -translate-x-1/2 z-50 w-96 max-w-[calc(100vw-2rem)]"
>
    <div class="p-2 flex items-center rounded-xl shadow-lg bg-gh-surface border border-gh-border"
        :class="current?.type === 'discard' && 'border-l-2 border-l-gh-accent'">
        <div class="flex-1 min-w-0 flex items-center gap-3 py-1.5 px-2.5 text-sm">
            <span
                x-text="current?.message"
                :title="current?.message"
                class="min-w-0 flex-1 truncate font-medium text-gh-text"
            ></span>
            <div class="flex items-center gap-3 shrink-0">
                <button
                    @click="undo()"
                    class="font-medium text-gh-link hover:underline"
                    data-testid="undo-button"
                >Undo</button>
                <template x-if="current?.type === 'discard'">
                    <button
                        @click="dismiss()"
                        class="font-medium text-gh-muted hover:text-gh-text"
                        data-testid="undo-ok-button"
                    >OK</button>
                </template>
                {{-- aria-hidden so the per-second countdown inside this aria-live
                     region doesn't re-announce the toast every tick; the message and
                     Undo action stay announced. --}}
                <span class="font-mono text-xs text-gh-muted tabular-nums" x-text="remaining + 's'" aria-hidden="true"></span>
            </div>
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

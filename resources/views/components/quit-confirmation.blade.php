{{--
    OrbStack-style hold-to-quit overlay. Pure Blade + Alpine — keeping it out
    of Livewire so the layout doesn't pay a per-page child-component mount.
    Confirmation routes to the `keepalive` Livewire component via a global
    Livewire event; that component owns the App::quit() seam.
--}}
<div
    x-data="{
        visible: false,
        armed: false,
        thresholdMs: 1500,
        autoDismissMs: 4000,
        holdTimer: null,
        dismissTimer: null,
        show() {
            this.visible = true;
            this.armed = false;
            this.armTimers();
        },
        armTimers() {
            this.clearTimers();

            // Assume Cmd+Q is held the moment the menu accelerator fired
            // (it just did, that's how we got here). If the user releases
            // before the threshold, keyup cancels.
            this.holdTimer = setTimeout(() => {
                this.armed = true;
            }, this.thresholdMs);

            // Auto-dismiss when the user clicks Quit in the menu with a
            // mouse and never follows up with Cmd+Q.
            this.dismissTimer = setTimeout(() => {
                if (! this.armed) {
                    this.cancel();
                }
            }, this.autoDismissMs);
        },
        clearTimers() {
            if (this.holdTimer) { clearTimeout(this.holdTimer); this.holdTimer = null; }
            if (this.dismissTimer) { clearTimeout(this.dismissTimer); this.dismissTimer = null; }
        },
        cancel() {
            this.clearTimers();
            this.visible = false;
            this.armed = false;
        },
        commit() {
            this.clearTimers();
            this.visible = false;
            this.armed = false;
            window.Livewire.dispatch('quit-now');
        },
        onKeyup(event) {
            if (! this.visible) { return; }
            if (event.key !== 'Meta' && event.key !== 'Control' && event.key.toLowerCase() !== 'q') { return; }

            if (this.armed) {
                this.commit();
            } else {
                this.cancel();
            }
        },
        onKeydown(event) {
            if (event.key === 'Escape' && this.visible) {
                event.preventDefault();
                this.cancel();
            }
        },
    }"
    @quit-prompt-show.window="show()"
    @keyup.window="onKeyup($event)"
    @keydown.window="onKeydown($event)"
>
    <template x-teleport="body">
        <div
            x-show="visible"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-out duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="cancel()"
            class="fixed inset-0 z-[80] bg-gh-bg/60 backdrop-blur-sm flex items-center justify-center"
            role="alertdialog"
            aria-live="polite"
            aria-label="Hold Command+Q to quit"
        >
            <div
                @click.stop
                x-show="visible"
                x-transition:enter="transition ease-out duration-200 delay-75"
                x-transition:enter-start="opacity-0 scale-[0.96]"
                x-transition:enter-end="opacity-100 scale-100"
                class="bg-gh-surface/95 border border-gh-border rounded-2xl shadow-2xl px-8 py-7 flex flex-col items-center gap-3 min-w-[200px]"
            >
                <flux:icon
                    icon="command-line"
                    variant="outline"
                    class="!size-7 text-gh-text"
                />
                <div class="font-display text-base font-semibold tracking-brutal text-gh-text whitespace-nowrap">
                    Hold <span class="font-mono">⌘Q</span> to Quit
                </div>
            </div>
        </div>
    </template>
</div>

{{--
    Pure Alpine + Tailwind (not Flux) because @fluxScripts is the dead
    dependency we'd be hiding behind.
--}}
<div
    x-data="{
        actionInFlight: false,
        fire(action) {
            if (this.actionInFlight) return;
            this.actionInFlight = true;
            window.rfaLifecycle?.[action]?.();
        },
    }"
    x-show="$store.backendHealth && $store.backendHealth.state !== 'connected'"
    x-cloak
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="rfa-backend-health-title"
    data-testid="backend-health-overlay"
    class="fixed inset-0 z-[9999] flex items-center justify-center bg-gh-bg/95 backdrop-blur-sm"
>
    <div class="max-w-md w-full mx-6 px-6 py-6 bg-gh-surface border border-gh-border rounded-lg shadow-2xl">
        <template x-if="$store.backendHealth && $store.backendHealth.state === 'reconnecting'">
            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-gh-link animate-spin shrink-0" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <h2
                        id="rfa-backend-health-title"
                        class="font-display text-lg tracking-brutal text-gh-text"
                    >Reconnecting to backend</h2>
                </div>
                <p class="text-sm text-gh-muted" data-testid="backend-health-attempts">
                    Attempt <span class="font-mono tabular-nums" x-text="$store.backendHealth?.attempts ?? 0"></span>.
                    The page will reload automatically when the backend is back.
                </p>
            </div>
        </template>

        <template x-if="$store.backendHealth && $store.backendHealth.state === 'unrecoverable'">
            <div class="flex flex-col gap-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-gh-red shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3l-6.93-12a2 2 0 00-3.48 0L3.34 16a2 2 0 001.73 3z" />
                    </svg>
                    <div class="flex flex-col gap-2 min-w-0">
                        <h2
                            id="rfa-backend-health-title"
                            class="font-display text-lg tracking-brutal text-gh-text"
                        >Backend won’t recover</h2>
                        <p class="text-sm text-gh-muted">
                            The PHP backend stopped responding. Restart the app to recover.
                        </p>
                        <pre
                            x-show="$store.backendHealth?.lastError"
                            class="text-xs font-mono text-gh-muted bg-gh-bg rounded px-2 py-1 overflow-x-auto whitespace-pre-wrap break-all"
                            x-text="$store.backendHealth?.lastError"
                            data-testid="backend-health-last-error"
                        ></pre>
                    </div>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        x-bind:disabled="actionInFlight"
                        @click="fire('forceQuit')"
                        data-testid="backend-health-force-quit"
                        class="font-display text-sm px-4 py-2 rounded-md border border-gh-border text-gh-text hover:bg-gh-hover-bg transition-colors disabled:opacity-50 disabled:cursor-wait"
                    >
                        <span x-show="!actionInFlight">Force Quit</span>
                        <span x-show="actionInFlight" x-cloak>Quitting…</span>
                    </button>
                    <button
                        type="button"
                        x-bind:disabled="actionInFlight"
                        @click="fire('restart')"
                        data-testid="backend-health-restart"
                        class="font-display text-sm px-4 py-2 rounded-md bg-gh-accent text-gh-bg hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-wait"
                    >
                        <span x-show="!actionInFlight">Restart RFA</span>
                        <span x-show="actionInFlight" x-cloak>Restarting…</span>
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>

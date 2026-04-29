@props(['version' => null])

<template x-teleport="body">
    <div
        x-data="{ open: false }"
        @restart-started.window="open = true"
        @restart-failed.window="open = false"
        x-show="open"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        class="fixed inset-0 z-[70] bg-gh-bg/85 backdrop-blur-md flex items-center justify-center"
        role="alertdialog"
        aria-live="polite"
        aria-label="Restarting to install update"
    >
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200 delay-75"
            x-transition:enter-start="opacity-0 scale-[0.97]"
            x-transition:enter-end="opacity-100 scale-100"
            class="w-[360px] bg-gh-bg border border-gh-border rounded-xl shadow-2xl px-8 py-9"
        >
            <div class="flex flex-col items-center text-center gap-5">
                <flux:icon icon="arrow-path" variant="outline" class="!size-7 text-gh-link animate-spin" />

                <div class="space-y-1.5">
                    <h2 class="font-display text-2xl font-bold tracking-brutal-tight text-gh-text">
                        Restarting
                    </h2>
                    @if($version)
                        <p class="font-mono text-xs text-gh-muted">
                            Installing v{{ $version }}
                        </p>
                    @endif
                </div>

                <p class="text-xs text-gh-muted leading-relaxed max-w-[260px]">
                    The app will close and relaunch in a moment.
                </p>

                <div class="w-full h-1 bg-gh-border rounded-full overflow-hidden mt-1" aria-hidden="true">
                    <div
                        class="h-full w-2/5 bg-gh-link rounded-full"
                        style="animation: rfa-restart-indeterminate 1.6s ease-in-out infinite;"
                    ></div>
                </div>
            </div>
        </div>
    </div>
</template>

{{--
    Branch-divergence marker for the header branch control.

    Renders a small dot on the branch pill when the repo's checked-out HEAD has
    drifted from the review target, with a popover offering the choice. Replaces
    the old full-width warning band so the canvas stays calm — the signal lives
    where its cause does. Only shows for the quiet, recoverable states
    (Diverged / Detached); MissingTarget is a blocking bar (<x-divergence.missing-bar>).

    Rendered inside the review page's `divergence-marker` island, where a
    wire:click would scope its render to the island. Buttons instead
    `$dispatch` bubbling `rfa-*` window events that the page-root
    Alpine forwards to `$wire`, so the actions settle the whole
    page (see the event schema in resources/CLAUDE.md).
--}}
@props(['state', 'context' => []])

@php
    $isDiverged = $state === \App\Enums\DivergenceState::Diverged;
    $isDetached = $state === \App\Enums\DivergenceState::Detached;
@endphp

@if($isDiverged || $isDetached)
    @php
        $target = $context['target'] ?? '';
        $currentBranch = $context['currentBranch'] ?? null;
        $shortSha = $context['shortSha'] ?? '';
        $commentCount = (int) ($context['commentCount'] ?? 0);

        // Literal class strings so Tailwind's source scan keeps them in the build.
        $dotClasses = $isDiverged ? 'bg-gh-attention ring-gh-attention/30' : 'bg-gh-link ring-gh-link/30';
        $testid = $isDiverged ? 'divergence-banner-diverged' : 'divergence-banner-detached';
    @endphp

    <div x-data="{ open: false }" class="relative inline-flex -ml-0.5" data-testid="{{ $testid }}">
        <button
            type="button"
            @click="open = ! open"
            @keydown.escape.window="open = false"
            :aria-expanded="open"
            aria-label="{{ $isDiverged ? 'Checkout moved off the review branch — show options' : 'Repo detached from the review branch — show options' }}"
            class="inline-flex items-center justify-center size-6 rounded-md hover:bg-gh-border/40 transition-colors"
        >
            <span class="relative inline-flex h-2 w-2 rounded-full ring-2 {{ $dotClasses }}"></span>
        </button>

        <div
            x-show="open"
            x-cloak
            @click.outside="open = false"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-out duration-100"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 -translate-y-1 scale-95"
            role="dialog"
            class="absolute left-0 top-full mt-2 z-50 w-[320px] origin-top-left rounded-xl border border-gh-border bg-gh-bg shadow-xl shadow-black/10 overflow-hidden"
        >
            <div class="px-4 pt-3.5 pb-3 space-y-2.5">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-2 w-2 rounded-full {{ $dotClasses }}"></span>
                    <span class="font-display font-semibold tracking-brutal">{{ $isDiverged ? 'Your checkout moved' : 'Repo is detached' }}</span>
                </div>

                @if($isDiverged)
                    <p class="text-xs text-gh-muted leading-relaxed">
                        Repo is on <span class="font-mono text-gh-text">{{ $currentBranch }}</span>.
                        RFA is still showing your review of <span class="font-mono text-gh-text">{{ $target }}</span>.
                    </p>
                    @if($commentCount > 0)
                        <p class="text-xs text-gh-muted">
                            <span class="text-gh-text font-medium tabular-nums">{{ $commentCount }}</span>
                            {{ \Illuminate\Support\Str::plural('comment', $commentCount) }} on <span class="font-mono">{{ $target }}</span>
                        </p>
                    @endif
                @else
                    <p class="text-xs text-gh-muted leading-relaxed">
                        Repo is detached at <span class="font-mono text-gh-text">{{ $shortSha }}</span>.
                        RFA is still showing your review of <span class="font-mono text-gh-text">{{ $target }}</span>.
                    </p>
                @endif
            </div>

            <div class="flex items-center justify-end gap-1 px-3 py-2.5 border-t border-gh-border bg-gh-surface/50">
                @if($isDiverged)
                    <button type="button" @click="open = false; $dispatch('rfa-keep-reviewing')" class="text-xs font-medium text-gh-muted hover:text-gh-text px-2.5 py-1.5 rounded-md transition-colors">Keep reviewing</button>
                    <button type="button" @click="open = false; $dispatch('rfa-switch-review-to-head')" class="text-xs font-medium font-display rounded-md px-3 py-1.5 bg-gh-accent text-gh-bg hover:opacity-90 transition-opacity">Switch review here</button>
                @else
                    <button type="button" @click="open = false; $dispatch('rfa-dismiss-detached-banner')" class="text-xs font-medium text-gh-muted hover:text-gh-text px-2.5 py-1.5 rounded-md transition-colors">Dismiss</button>
                @endif
            </div>
        </div>
    </div>
@endif

{{--
    The blue (clickable) targets inside <x-diff.expand-control>: a single
    "N hidden lines" button for small gaps, or tiered chips (15 · 50 · 100)
    plus an "all N hidden lines" button for larger ones. The "Show" verb, ↕
    icon, band chrome, and `loading` flag are owned by the wrapping shell —
    buttons here just flip `loading = true`.
--}}
@props(['hunkIndex', 'hiddenCount'])

@php
    // Hot path: rendered for every hunk gap of every diff. Native array_filter
    // beats `collect(...)->filter()->values()` here — the Collection wrapper
    // allocation per gap shows up in the diff-render benchmarks.
    $applicableTiers = array_values(array_filter([15, 50, 100], fn ($t) => $t < $hiddenCount));
@endphp

@if(empty($applicableTiers))
    <button
        wire:click="expandGap({{ $hunkIndex }})"
        wire:loading.attr="disabled"
        wire:target="expandGap"
        @click="loading = true; markDiffActionStart('expandGap')"
        class="text-gh-link hover:text-gh-text transition-colors disabled:opacity-50 tabular-nums"
    >
        {{-- Inline ternary instead of Str::plural for the same hot-path reason. --}}
        {{ $hiddenCount }} hidden {{ $hiddenCount === 1 ? 'line' : 'lines' }}
    </button>
@else
    <span class="inline-flex items-center gap-1">
        @foreach($applicableTiers as $tier)
            @if(!$loop->first)
                <span class="text-gh-muted/20" aria-hidden="true">&middot;</span>
            @endif
            <button
                wire:click="expandGap({{ $hunkIndex }}, {{ $tier }})"
                wire:loading.attr="disabled"
                wire:target="expandGap"
                @click="loading = true; markDiffActionStart('expandGap')"
                class="text-gh-link hover:bg-gh-link/10 hover:text-gh-text rounded px-1.5 py-0.5 transition-colors disabled:opacity-50 tabular-nums"
            >{{ $tier }}</button>
        @endforeach
    </span>
    {{-- Always plural here: this branch only renders when hiddenCount > 15. The
         full "N hidden lines" target reads identically to the single-gap button. --}}
    <button
        wire:click="expandGap({{ $hunkIndex }})"
        wire:loading.attr="disabled"
        wire:target="expandGap"
        @click="loading = true; markDiffActionStart('expandGap')"
        class="text-gh-link hover:bg-gh-link/10 hover:text-gh-text rounded px-1.5 py-0.5 transition-colors disabled:opacity-50 tabular-nums"
    >{{ $hiddenCount }} hidden lines</button>
@endif

@props(['hunkIndex', 'hiddenCount'])

@php
    $expandTiers = [15, 50, 100];
    $applicableTiers = collect($expandTiers)->filter(fn ($t) => $t < $hiddenCount)->values();
@endphp

<span wire:key="expand-gap-{{ $hunkIndex }}-{{ $hiddenCount }}" x-data="{ loading: false }">
    @if($applicableTiers->isEmpty())
        <button
            wire:click="expandGap({{ $hunkIndex }})"
            wire:loading.attr="disabled"
            wire:target="expandGap"
            @click="loading = true"
            x-show="!loading"
            class="text-gh-link hover:text-gh-text transition-colors inline-flex items-center gap-1.5 disabled:opacity-50"
        >
            Expand {{ $hiddenCount }} hidden {{ Str::plural('line', $hiddenCount) }}
        </button>
    @else
        <span x-show="!loading" class="inline-flex items-center gap-1.5">
            <span class="text-gh-muted/60 select-none">Expand</span>
            <span class="inline-flex items-center">
                @foreach($applicableTiers as $tier)
                    @if(!$loop->first)
                        <span class="text-gh-muted/20 select-none" aria-hidden="true">&middot;</span>
                    @endif
                    <button
                        wire:click="expandGap({{ $hunkIndex }}, {{ $tier }})"
                        wire:loading.attr="disabled"
                        wire:target="expandGap"
                        @click="loading = true"
                        class="text-gh-link hover:bg-gh-link/10 hover:text-gh-text rounded px-1.5 py-0.5 transition-colors disabled:opacity-50 tabular-nums"
                    >{{ $tier }}</button>
                @endforeach
            </span>
            <button
                wire:click="expandGap({{ $hunkIndex }})"
                wire:loading.attr="disabled"
                wire:target="expandGap"
                @click="loading = true"
                class="text-gh-link hover:bg-gh-link/10 hover:text-gh-text rounded px-1.5 py-0.5 transition-colors disabled:opacity-50 tabular-nums"
            >{{ $hiddenCount }} <span class="text-gh-muted/60">hidden {{ Str::plural('line', $hiddenCount) }}</span></button>
        </span>
    @endif
    <span x-show="loading" x-cloak class="inline-flex items-center gap-1.5 text-gh-muted">
        <flux:icon icon="arrow-path" variant="outline" class="size-3.5 animate-spin" />
        Expanding...
    </span>
</span>

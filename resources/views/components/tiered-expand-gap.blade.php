@props(['hunkIndex', 'hiddenCount', 'expandTiers' => [15, 50, 100]])

@php $applicableTiers = collect($expandTiers)->filter(fn ($t) => $t < $hiddenCount)->values(); @endphp

@if($applicableTiers->isEmpty())
    <button
        wire:click="expandGap({{ $hunkIndex }})"
        wire:loading.attr="disabled"
        wire:target="expandGap"
        class="text-gh-link hover:text-gh-text transition-colors inline-flex items-center gap-1.5 disabled:opacity-50"
    >
        <flux:icon wire:loading wire:target="expandGap" icon="arrow-path" variant="outline" class="size-3.5 animate-spin" />
        <span wire:loading.remove wire:target="expandGap">Expand {{ $hiddenCount }} hidden lines</span>
        <span wire:loading wire:target="expandGap">Expanding...</span>
    </button>
@else
    <span wire:loading.remove wire:target="expandGap" class="inline-flex items-center gap-1.5">
        <span class="text-gh-muted/60 select-none">Expand</span>
        <span class="inline-flex items-center">
            @foreach($applicableTiers as $tier)
                <button
                    wire:click="expandGap({{ $hunkIndex }}, {{ $tier }})"
                    wire:loading.attr="disabled"
                    wire:target="expandGap"
                    class="text-gh-link hover:bg-gh-link/10 hover:text-gh-text rounded px-1.5 py-0.5 transition-colors disabled:opacity-50 tabular-nums"
                >{{ $tier }}</button>
                <span class="text-gh-muted/20 select-none">&middot;</span>
            @endforeach
            <button
                wire:click="expandGap({{ $hunkIndex }})"
                wire:loading.attr="disabled"
                wire:target="expandGap"
                class="text-gh-link hover:bg-gh-link/10 hover:text-gh-text rounded px-1.5 py-0.5 transition-colors disabled:opacity-50 tabular-nums"
            >{{ $hiddenCount }} <span class="text-gh-muted/60">hidden lines</span></button>
        </span>
    </span>
    <span wire:loading wire:target="expandGap" class="inline-flex items-center gap-1.5 text-gh-muted">
        <flux:icon icon="arrow-path" variant="outline" class="size-3.5 animate-spin" />
        Expanding...
    </span>
@endif

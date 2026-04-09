@props(['hunkIndex', 'hiddenCount', 'expandTiers' => [15, 50, 100]])

@php $applicableTiers = collect($expandTiers)->filter(fn ($t) => $t < $hiddenCount)->values(); @endphp

@if($applicableTiers->isEmpty())
    <button
        wire:click="expandGap({{ $hunkIndex }})"
        wire:loading.attr="disabled"
        wire:target="expandGap"
        class="text-gh-link hover:underline inline-flex items-center gap-1 disabled:opacity-50"
    >
        <flux:icon wire:loading wire:target="expandGap" icon="arrow-path" variant="outline" class="animate-spin" />
        <span wire:loading.remove wire:target="expandGap">Expand {{ $hiddenCount }} hidden lines</span>
        <span wire:loading wire:target="expandGap">Expanding...</span>
    </button>
@else
    <span wire:loading.remove wire:target="expandGap" class="inline-flex items-center gap-0.5">
        <span class="text-gh-muted">Expand</span>
        @foreach($applicableTiers as $tier)
            <button wire:click="expandGap({{ $hunkIndex }}, {{ $tier }})" wire:loading.attr="disabled" wire:target="expandGap" class="text-gh-link hover:underline disabled:opacity-50">{{ $tier }}</button>
            <span class="text-gh-muted/50">&middot;</span>
        @endforeach
        <button wire:click="expandGap({{ $hunkIndex }})" wire:loading.attr="disabled" wire:target="expandGap" class="text-gh-link hover:underline disabled:opacity-50">{{ $hiddenCount }} <span class="text-gh-muted">hidden lines</span></button>
    </span>
    <span wire:loading wire:target="expandGap" class="inline-flex items-center gap-1">
        <flux:icon icon="arrow-path" variant="outline" class="animate-spin" />
        Expanding...
    </span>
@endif

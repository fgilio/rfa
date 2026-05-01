@props([
    'sortBy' => 'recent',
    'target' => 'sortBy',
])

@php
    $nextSort = $sortBy === 'recent' ? 'alpha' : 'recent';
    $nextSortLabel = $sortBy === 'recent' ? 'Sort A–Z' : 'Sort by recent';
@endphp

<flux:button
    variant="ghost"
    size="sm"
    wire:click="$set('{{ $target }}', @js($nextSort))"
    class="text-gh-muted hover:text-gh-text font-mono text-xs shrink-0"
    :tooltip="$nextSortLabel"
>
    <flux:icon icon="arrows-up-down" variant="outline" class="!size-3.5" />
    <span>{{ $sortBy === 'recent' ? 'Recent' : 'A–Z' }}</span>
</flux:button>

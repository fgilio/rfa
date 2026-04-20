@props([
    'tooltip',
    'ariaLabel',
    'variant' => 'display',
])

@php
    $textClasses = $variant === 'mono'
        ? 'text-xs font-mono text-gh-muted hover:text-gh-text'
        : 'font-display font-bold tracking-brutal-tight text-base hover:text-gh-link';
@endphp

<flux:tooltip content="{{ $tooltip }}">
    <button
        type="button"
        {{ $attributes->merge(['class' => "group inline-flex items-center gap-1 leading-none cursor-pointer transition-colors $textClasses"]) }}
        aria-label="{{ $ariaLabel }}"
        aria-haspopup="dialog"
    >
        {{ $slot }}
        <x-chevron-icon :variant="$variant" />
    </button>
</flux:tooltip>

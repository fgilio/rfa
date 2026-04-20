@props(['variant' => 'display'])

@php
    $classes = $variant === 'mono'
        ? '!size-3 transition-colors text-gh-muted/60 group-hover:text-gh-text'
        : '!size-3 transition-colors text-gh-muted group-hover:text-gh-link';
@endphp

<flux:icon icon="chevron-down" variant="outline" :class="$classes" />

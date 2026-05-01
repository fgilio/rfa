@props([
    'size' => 'sm',
    'label' => null,
])

@php
    // Static class strings so Tailwind's source scan keeps these in the build.
    $dimensions = match ($size) {
        'md' => 'h-2.5 w-2.5',
        default => 'h-2 w-2',
    };
@endphp

{{-- Animated dot with a soft ping ring. Used wherever the user has work
     elsewhere they should be aware of (mode-toggle indicators, change
     polling, etc.). Always renders the gh-attention token because the
     semantic ("come look at this") never changes between call sites.

     :size accepts 'sm' (default, 8px) or 'md' (10px).
     :label adds an sr-only description for screen readers. --}}
<span aria-hidden="true" {{ $attributes->class(['pointer-events-none flex', $dimensions]) }}>
    <span class="animate-ping absolute inline-flex {{ $dimensions }} rounded-full bg-gh-attention opacity-75"></span>
    <span class="relative inline-flex {{ $dimensions }} rounded-full bg-gh-attention"></span>
</span>
@if ($label)
    <span class="sr-only">{{ $label }}</span>
@endif

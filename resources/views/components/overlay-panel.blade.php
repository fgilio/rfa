@props([
    'name',
    'ariaLabel',
    'size' => 'md',
    'open' => 'open',
    'onClose' => 'close()',
])

@php
    // Every overlay shares one stage in the top-left so ⌘K / ⌘B / ⌘J all
    // reveal a panel with the same shape, motion, and dismiss behavior.
    $widths = [
        'md' => 'w-[460px]',
        'lg' => 'w-[560px]',
    ];
    $width = $widths[$size] ?? $widths['md'];
@endphp

<template x-teleport="body">
    <div x-show="{{ $open }}" x-cloak class="fixed inset-0 z-[60]" @click.self="{{ $onClose }}">
        <div
            x-show="{{ $open }}"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0 bg-black/30"
            @click="{{ $onClose }}"
        ></div>

        <div
            x-show="{{ $open }}"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
            @click.stop
            role="dialog"
            aria-label="{{ $ariaLabel }}"
            data-overlay-panel="{{ $name }}"
            data-testid="overlay-panel-{{ $name }}"
            {{ $attributes->merge(['class' => "fixed top-[calc(var(--header-h,56px)+6px)] left-4 z-[61] $width max-w-[calc(100vw-32px)] max-h-[70vh] bg-gh-bg border border-gh-border rounded-xl shadow-2xl flex flex-col overflow-hidden"]) }}
        >
            {{ $slot }}
        </div>
    </div>
</template>

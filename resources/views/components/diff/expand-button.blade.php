{{--
    A clickable target inside <x-diff.expand-control>. Centralizes the expand
    button's wire contract so each call site names its Livewire `action` exactly
    once — it drives wire:click, wire:target, and the markDiffActionStart timing
    mark, and flips the shell's shared `loading` Alpine flag on click. The
    gh-link affordance styling is the base; callers merge per-button classes
    (chip padding, tabular-nums) via the class attribute.
--}}
@props([
    'action',
    'args' => '',
])

<button
    wire:click="{{ $action }}({{ $args }})"
    wire:loading.attr="disabled"
    wire:target="{{ $action }}"
    @click="loading = true; markDiffActionStart('{{ $action }}')"
    {{ $attributes->class('text-gh-link hover:text-gh-text transition-colors disabled:opacity-50') }}
>{{ $slot }}</button>

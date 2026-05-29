{{--
    A clickable target inside <x-diff.expand-control>. Centralizes the expand
    button's wire contract so each call site names its Livewire `action` exactly
    once — it drives wire:click, wire:target, and the markDiffActionStart timing
    mark, and flips the shell's shared `loading` Alpine flag on click. The
    gh-link affordance styling is the base; callers merge per-button classes
    (chip padding, tabular-nums) via the class attribute.

    `gapKey` (the hunk index, set by gap expanders) tags the button with
    data-expand-gap and arms keyboard-only focus restoration: when the
    post-expand re-render replaces this node, focus returns to the expander that
    now sits at the same gap instead of dropping to <body>. Mouse clicks don't
    arm it, and the master "full file" expander leaves it null (no gap remains).
--}}
@props([
    'action',
    'args' => '',
    'gapKey' => null,
])

<button
    wire:click="{{ $action }}({{ $args }})"
    wire:loading.attr="disabled"
    wire:target="{{ $action }}"
    @if($gapKey !== null) data-expand-gap="{{ $gapKey }}" @endif
    @click="loading = true; markDiffActionStart('{{ $action }}'); armExpandRefocus($event, @js($gapKey))"
    {{ $attributes->class('text-gh-link hover:text-gh-text transition-colors disabled:opacity-50') }}
>{{ $slot }}</button>

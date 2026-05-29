{{--
    Unified chrome for every collapsed-content affordance in a diff: the
    "show full file" master expander, per-gap tiered expanders, the trailing
    gap, and the (non-expandable) section-context label.

    Coherence contract: the verb ("Show") is rendered here, once, as a muted
    lead-in — never inside a button. Everything blue (gh-link) is clickable and
    lives in the slot: "full file", "8 hidden lines", the "15 · 50 · 100" chips.
    So every expander reads `↕ Show <blue target>` with identical styling.

    Expandable bands carry the `expand-all` (↕) icon, a dashed top/bottom rule
    (dashed = collapsed content lives here), and own a shared `loading` Alpine
    flag so any button in the slot can swap the row to a spinner with
    `@click="loading = true"`. The section-context label passes `:icon="false"`
    (and `align="start"`) — no affordance, no verb, no rule, just the context.
--}}
@props([
    'icon' => 'expand-all',
    'align' => 'center',
])

@php
    $hasIcon = $icon !== false && $icon !== null;
@endphp

<div
    {{ $attributes->class([
        'diff-fullspan bg-gh-hunk-bg px-4 py-1.5 text-xs font-mono flex items-center gap-2',
        'border-y border-dashed border-gh-border/20' => $hasIcon,
        'justify-center select-none' => $align === 'center',
        'justify-start' => $align === 'start',
    ]) }}
    @if($hasIcon) x-data="{ loading: false }" @endif
>
    @if($hasIcon)
        <flux:icon :icon="$icon" variant="outline" class="!size-3.5 shrink-0 text-gh-muted/50" x-show="!loading" />
        <span x-show="!loading" class="inline-flex min-w-0 items-center gap-2">
            <span class="text-gh-muted/60">Show</span>
            {{ $slot }}
        </span>
        <flux:icon icon="arrow-path" variant="outline" class="!size-3.5 shrink-0 animate-spin text-gh-muted" x-show="loading" x-cloak />
        <span x-show="loading" x-cloak class="text-gh-muted">Loading…</span>
    @else
        {{ $slot }}
    @endif
</div>

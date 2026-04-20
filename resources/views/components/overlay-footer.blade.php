@props(['meta' => null])

<div class="px-3 py-2 border-t border-gh-border flex items-center justify-between shrink-0 bg-gh-surface/50">
    <span class="font-mono text-[11px] text-gh-muted">
        {{ $meta }}
    </span>
    <span class="font-mono text-[11px] text-gh-muted/60 flex items-center gap-2">
        @isset($hints)
            {{ $hints }}
        @else
            <x-kbd-hint :keys="['↑', '↓']"> nav</x-kbd-hint>
            <x-kbd-hint keys="↵"> open</x-kbd-hint>
            <x-kbd-hint keys="esc"> close</x-kbd-hint>
        @endisset
    </span>
</div>

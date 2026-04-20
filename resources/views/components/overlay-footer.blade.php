@props(['meta' => null])

<div class="px-3 py-2 border-t border-gh-border flex items-center justify-between shrink-0 bg-gh-surface/50">
    <span class="font-mono text-[11px] text-gh-muted">
        {{ $meta }}
    </span>
    <span class="font-mono text-[11px] text-gh-muted/60 flex items-center gap-2">
        <span><kbd class="px-1 py-0.5 rounded border border-gh-border text-[10px]">↑</kbd><kbd class="px-1 py-0.5 rounded border border-gh-border text-[10px]">↓</kbd> nav</span>
        <span><kbd class="px-1 py-0.5 rounded border border-gh-border text-[10px]">↵</kbd> open</span>
        <span><kbd class="px-1 py-0.5 rounded border border-gh-border text-[10px]">esc</kbd> close</span>
    </span>
</div>

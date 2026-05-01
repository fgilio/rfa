@props([
    'sourceFiles',
    'reviewPairs',
])

{{-- State band under the page header: file count, +/- totals, review count,
     reviewed-progress meter, and a copy-paths affordance. Pure state — no
     actions. Inherits Alpine state from the parent ⚡review-page scope:
     `fileFilter`, `hideReviewed`, `sourceFileEntries`, `fileMatchesFilter`,
     `reviewedCount`. --}}
<div data-testid="status-strip" class="bg-gh-bg/60 border-b border-gh-border px-5 py-1 flex items-center gap-3 font-mono text-[11px] text-gh-muted">
    <span
        x-text="fileFilter === '' && !hideReviewed
            ? '{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}'
            : sourceFileEntries.filter(f => fileMatchesFilter(f.path, f.id)).length + '/{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}'"
    >{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}</span>
    <span class="text-gh-green">+{{ collect($sourceFiles)->sum('additions') }}</span>
    <span class="text-gh-red">-{{ collect($sourceFiles)->sum('deletions') }}</span>

    @if(count($reviewPairs) > 0)
        <span class="px-1.5 py-px rounded border border-gh-border">{{ count($reviewPairs) }} {{ Str::plural('review', count($reviewPairs)) }}</span>
    @endif

    <div class="ml-auto flex items-center gap-2">
        <div x-show="reviewedCount > 0" x-cloak class="flex items-center gap-2">
            <span data-testid="reviewed-counter" x-text="reviewedCount + '/{{ count($sourceFiles) }} reviewed'"></span>
            <div class="w-24 h-0.5 bg-gh-border/50 rounded-full overflow-hidden">
                <div class="h-full bg-gh-green/70 rounded-full transition-all duration-200" :style="'width:' + Math.round(reviewedCount / {{ count($sourceFiles) }} * 100) + '%'"></div>
            </div>
        </div>
        @if(count($sourceFiles) > 0)
            <x-copy-paths-menu testid-prefix="status-strip-copy-paths" />
        @endif
    </div>
</div>

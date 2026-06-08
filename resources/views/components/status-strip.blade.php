@props([
    'sourceFiles',
    'reviewPairs',
    'reviewState' => null,
])

@php
    $totalFileCount = $reviewState?->totalFileCount ?? count($sourceFiles);
    $totalAdditions = $reviewState?->additions ?? collect($sourceFiles)->sum('additions');
    $totalDeletions = $reviewState?->deletions ?? collect($sourceFiles)->sum('deletions');
@endphp

{{-- State band under the page header: file count, +/- totals, review count,
     reviewed-progress meter, and a copy-paths affordance. Pure state, no
     actions. Inherits Alpine state from the parent ⚡review-page scope:
     `fileFilter`, `hideReviewed`, `sourceFileEntries`, `fileMatchesFilter`,
     `reviewedCount`. --}}
<div data-testid="status-strip" class="bg-gh-bg/60 border-b border-gh-border px-5 py-1 flex items-center gap-3 font-mono text-[11px] text-gh-muted">
    <span
        x-text="fileFilter === '' && !hideReviewed
            ? '{{ $totalFileCount }} {{ Str::plural('file', $totalFileCount) }}'
            : sourceFileEntries.filter(f => fileMatchesFilter(f.path, f.id)).length + '/{{ $totalFileCount }} {{ Str::plural('file', $totalFileCount) }}'"
    >{{ $totalFileCount }} {{ Str::plural('file', $totalFileCount) }}</span>
    <span class="text-gh-green">+{{ $totalAdditions }}</span>
    <span class="text-gh-red">-{{ $totalDeletions }}</span>

    @if(count($reviewPairs) > 0)
        <span class="text-gh-muted">{{ count($reviewPairs) }} {{ Str::plural('review', count($reviewPairs)) }}</span>
    @endif

    <div class="ml-auto flex items-center gap-2">
        <div x-show="reviewedCount > 0" x-cloak class="flex items-center gap-2">
            <span data-testid="reviewed-counter" x-text="reviewedCount + '/{{ $totalFileCount }} reviewed'"></span>
            <div class="w-24 h-0.5 bg-gh-border/50 rounded-full overflow-hidden">
                <div class="h-full bg-gh-green/70 rounded-full transition-all duration-200" :style="'width:' + Math.round(reviewedCount / {{ max(1, $totalFileCount) }} * 100) + '%'"></div>
            </div>
        </div>
        @if($totalFileCount > 0)
            <x-copy-paths-button testid-prefix="status-strip-copy-paths" />
        @endif
    </div>
</div>

@props([
    'sourceFiles',
    'reviewPairs',
    'reviewState' => null,
])

@php
    $totalFileCount = $reviewState?->totalFileCount ?? count($sourceFiles);
    $visibleFileCount = $reviewState?->visibleFileCount ?? $totalFileCount;
    $totalAdditions = $reviewState?->additions ?? collect($sourceFiles)->sum('additions');
    $totalDeletions = $reviewState?->deletions ?? collect($sourceFiles)->sum('deletions');
@endphp

{{-- State band under the page header: file count, +/- totals, review count, and
     a copy-paths affordance. Pure state, no actions. Visibility is derived
     server-side by ReviewState. The reviewed-progress summary is injected via
     the $reviewedSummary slot so the page can render it inside a Livewire
     island (the @island directive needs the Livewire view's scope). --}}
<div data-testid="status-strip" class="bg-gh-bg/60 border-b border-gh-border px-5 py-1 flex items-center gap-3 font-mono text-[11px] text-gh-muted">
    <span>
        @if($visibleFileCount === $totalFileCount)
            {{ $totalFileCount }} {{ Str::plural('file', $totalFileCount) }}
        @else
            {{ $visibleFileCount }}/{{ $totalFileCount }} {{ Str::plural('file', $totalFileCount) }}
        @endif
    </span>
    <span class="text-gh-green">+{{ $totalAdditions }}</span>
    <span class="text-gh-red">-{{ $totalDeletions }}</span>

    @if(count($reviewPairs) > 0)
        <span class="text-gh-muted">{{ count($reviewPairs) }} {{ Str::plural('review', count($reviewPairs)) }}</span>
    @endif

    <div class="ml-auto flex items-center gap-2">
        {{ $reviewedSummary ?? '' }}
        @if($visibleFileCount > 0)
            <x-copy-paths-button
                testid-prefix="status-strip-copy-paths"
                :visible-count="$visibleFileCount"
            />
        @endif
    </div>
</div>

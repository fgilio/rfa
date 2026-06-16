@props([
    'sourceFiles',
    'reviewPairs',
    'reviewState' => null,
    'fileCount' => null,
])

@php
    $totalFileCount = $reviewState?->totalFileCount ?? count($sourceFiles);
    $visibleFileCount = $reviewState?->visibleFileCount ?? $totalFileCount;
    $totalAdditions = $reviewState?->additions ?? collect($sourceFiles)->sum('additions');
    $totalDeletions = $reviewState?->deletions ?? collect($sourceFiles)->sum('deletions');
@endphp

{{-- State band under the page header: file count, +/- totals, review count, and
     a copy-paths affordance. Pure state, no actions. Visibility is derived
     server-side by ReviewState. The reviewed-progress summary and the file count
     are injected via the $reviewedSummary / $fileCount slots so the page can
     render them inside Livewire islands (the @island directive needs the Livewire
     view's scope) — the count must refresh when Hide-reviewed changes the visible
     set even though the strip itself isn't re-rendered on that skipRender path. --}}
<div data-testid="status-strip" class="bg-gh-bg/60 border-b border-gh-border px-5 py-1 flex items-center gap-3 font-mono text-[11px] text-gh-muted">
    @isset($fileCount)
        {{ $fileCount }}
    @else
        <span>
            @if($visibleFileCount === $totalFileCount)
                {{ $totalFileCount }} {{ Str::plural('file', $totalFileCount) }}
            @else
                {{ $visibleFileCount }}/{{ $totalFileCount }} {{ Str::plural('file', $totalFileCount) }}
            @endif
        </span>
    @endisset
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

{{-- The amber dot only renders on the *other* side: while you're on that
     mode, the activity is not a navigation hint. --}}
@props([
    'mode',
    'projectSlug',
    'hasReviewActivity' => false,
    'hasContextActivity' => false,
])

@php
    $reviewActive = $mode === 'review';
    $contextActive = $mode === 'context';

    $reviewHref = route('review-page', ['slug' => $projectSlug]);
    $contextHref = route('context-page', ['slug' => $projectSlug]);

    $baseSide = 'group relative inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-display tracking-tight transition-colors cursor-pointer';
    $activeSide = 'bg-gh-surface text-gh-text ring-1 ring-gh-border';
    $inactiveSide = 'text-gh-muted hover:text-gh-text hover:bg-gh-border/25';

    $reviewClasses = $baseSide.' rounded-l-md '.($reviewActive ? $activeSide : $inactiveSide);
    $contextClasses = $baseSide.' rounded-r-md '.($contextActive ? $activeSide : $inactiveSide);

    $showReviewDot = $hasReviewActivity && ! $reviewActive;
    $showContextDot = $hasContextActivity && ! $contextActive;
@endphp

<div class="inline-flex items-stretch rounded-md border border-gh-border/70 bg-gh-surface/30 hover:border-gh-text/30 transition-colors" role="group" aria-label="Switch between review and context">
    <a
        href="{{ $reviewHref }}"
        wire:navigate
        @if($reviewActive) aria-current="page" @endif
        class="{{ $reviewClasses }}"
    >
        <span>Review</span>
        @if($showReviewDot)
            <span aria-hidden="true" class="pointer-events-none flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-2 w-2 rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
            </span>
            <span class="sr-only">unsubmitted comments on review</span>
        @endif
    </a>

    <span class="w-px self-stretch bg-gh-border/70" aria-hidden="true"></span>

    <a
        href="{{ $contextHref }}"
        wire:navigate
        @if($contextActive) aria-current="page" @endif
        class="{{ $contextClasses }}"
    >
        <span>Context</span>
        @if($showContextDot)
            <span aria-hidden="true" class="pointer-events-none flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-2 w-2 rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
            </span>
            <span class="sr-only">unsubmitted comments on context</span>
        @endif
    </a>
</div>

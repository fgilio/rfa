{{--
    Content-shaped placeholder for a diff file while its hunks load — a few
    line-number + content bars in the diff's own rhythm, so the layout doesn't
    jump when the real diff arrives. `animate-pulse` is collapsed by the global
    reduce-motion rule. Decorative, so aria-hidden.
--}}
@props(['rows' => 7])

@php
    // Varied bar widths read as code rather than a flat block.
    $widths = [86, 58, 72, 44, 90, 64, 50, 78, 38, 68];
@endphp

<div class="px-4 py-3 motion-safe:animate-pulse" aria-hidden="true">
    @for($i = 0; $i < $rows; $i++)
        <div class="flex items-center gap-3 py-1">
            <div class="h-3 w-6 rounded-sm bg-gh-border/60 shrink-0"></div>
            <div class="h-3 rounded-sm bg-gh-border/40" style="width: {{ $widths[$i % count($widths)] }}%"></div>
        </div>
    @endfor
</div>

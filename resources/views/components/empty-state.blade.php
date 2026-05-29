{{--
    Shared full-page empty / zero state — one visual language across the app.

    Renders the rfa wordmark watermark (or a Flux icon), a display heading, a
    free body slot, and an optional actions row. Pass role / aria-live through
    as attributes for alert-grade states (e.g. git errors).

    Props:
      glyph       wordmark text (default "rfa"); set to null to omit
      glyphClass  color for the wordmark watermark (default muted/20)
      icon        Flux icon name to use instead of the wordmark
      size        "lg" (default, ~60vh centered) or "sm" (py-16)
    Slots: heading, default (body), actions
--}}
@props([
    'glyph' => 'rfa',
    'glyphClass' => 'text-gh-muted/20',
    'icon' => null,
    'size' => 'lg',
])

<div {{ $attributes->class(['flex items-center justify-center', $size === 'sm' ? 'py-16' : 'h-[60vh]']) }}>
    <div class="text-center px-6 max-w-md">
        @if($icon)
            <flux:icon :icon="$icon" variant="outline" class="!size-10 text-gh-muted/30 mx-auto mb-5" />
        @elseif($glyph)
            <p class="rfa-logo text-5xl {{ $glyphClass }} mb-6" aria-hidden="true">{{ $glyph }}</p>
        @endif

        @isset($heading)
            <h2 class="font-display font-semibold tracking-brutal text-lg text-gh-text mb-2">{{ $heading }}</h2>
        @endisset

        {{ $slot }}

        @isset($actions)
            <div class="flex items-center justify-center gap-2 mt-5">{{ $actions }}</div>
        @endisset
    </div>
</div>

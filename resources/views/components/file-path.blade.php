{{--
    Renders a file path with identifier-first hierarchy via color: directory
    dimmed, basename emphasized.

    Layout: a single block-level `truncate` line. The whole line ellipsizes
    from the right when it overflows. The previous "directory ellipsizes
    first, basename stays whole" rule was a layout footgun in nested flex
    contexts (every fix triggered a new edge case), so this version drops it
    in favor of one bulletproof box. `text-left` overrides any inherited
    text-align (e.g. `<button>`'s default `center`) so short paths don't
    drift toward the middle when the box is wider than the text.

    Color emphasis is via real muted color (not opacity) so it composes with
    any parent text color — caller controls base hue, the component handles
    weight via the inner color spans.
--}}
@props([
    'path',
    'oldPath' => null,
])

@php
    $pos = strrpos($path, '/');
    [$dir, $base] = $pos === false ? ['', $path] : [substr($path, 0, $pos + 1), substr($path, $pos + 1)];
    $hasOldPath = $oldPath !== null && $oldPath !== '';
    $defaultTitle = $hasOldPath ? $oldPath.' → '.$path : $path;
@endphp

<span {{ $attributes->merge(['class' => 'font-mono block truncate min-w-0 max-w-full text-left text-gh-text', 'title' => $defaultTitle]) }}>@if($hasOldPath)<span class="text-gh-muted/50">{{ $oldPath }}&nbsp;→&nbsp;</span>@endif<span class="text-gh-muted/70">{{ $dir }}</span>{{ $base }}</span>

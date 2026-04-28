{{--
    Renders a file path with identifier-first hierarchy: directory dimmed,
    basename emphasized. See resources/CLAUDE.md "Paths & identifiers" for the
    rules this component encodes.

    Layout: an inline-flex with a shrinkable directory span and a shrink-0
    basename span. When the row overflows, the directory ellipsizes; the
    basename always stays whole.

    Color emphasis is relative (opacity), so the component composes with any
    parent text color — caller controls base hue, the component handles weight.
--}}
@props([
    'path',
    'oldPath' => null,
])

@php
    $splitPath = static function (?string $value): array {
        if ($value === null || $value === '') {
            return ['', ''];
        }
        $pos = strrpos($value, '/');
        if ($pos === false) {
            return ['', $value];
        }
        return [substr($value, 0, $pos + 1), substr($value, $pos + 1)];
    };

    $hasOldPath = $oldPath !== null && $oldPath !== '';
    [$dir, $base] = $splitPath($path);
    $defaultTitle = $hasOldPath ? $oldPath.' → '.$path : $path;
@endphp

<span {{ $attributes->merge(['class' => 'font-mono inline-flex items-baseline min-w-0 max-w-full', 'title' => $defaultTitle]) }}>
    @if($hasOldPath)
        <span class="min-w-0 truncate opacity-50">{{ $oldPath }}</span><span class="shrink-0 opacity-50">&nbsp;→&nbsp;</span>
    @endif
    <span class="min-w-0 truncate opacity-60">{{ $dir }}</span><span class="shrink-0 font-semibold">{{ $base }}</span>
</span>

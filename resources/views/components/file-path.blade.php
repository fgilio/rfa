{{--
    Renders a file path with identifier-first hierarchy: directory dimmed,
    basename emphasized. See resources/CLAUDE.md "Paths & identifiers" for the
    rules this component encodes.

    Layout:
    - With a directory (or rename old-path), an inline-flex with a shrinkable
      directory span and a shrink-0 basename span. When the row overflows,
      the directory ellipsizes; the basename stays whole. The wrapper also
      sets overflow-hidden so a basename wider than the available width
      cannot bleed past its bounds.
    - For root-level paths (no directory) there's nothing to give up, so the
      basename itself must truncate. Render a single `inline-block truncate`
      span so width resolution and ellipsis behavior are unambiguous (no
      nested flex shrinking, which proved unreliable here). Stays inline-level
      to match the dir branch's outer box model.

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
    $needsFlexLayout = $hasOldPath || $dir !== '';
@endphp

@if($needsFlexLayout)
    <span {{ $attributes->merge(['class' => 'font-mono inline-flex items-baseline min-w-0 max-w-full overflow-hidden', 'title' => $defaultTitle]) }}>
        @if($hasOldPath)
            <span class="min-w-0 truncate text-gh-muted/50">{{ $oldPath }}</span><span class="shrink-0 text-gh-muted/50">&nbsp;→&nbsp;</span>
        @endif
        @if($dir !== '')
            <span class="min-w-0 truncate text-gh-muted/70">{{ $dir }}</span><span class="shrink-0 text-gh-text">{{ $base }}</span>
        @else
            <span class="min-w-0 truncate text-gh-text">{{ $base }}</span>
        @endif
    </span>
@else
    <span {{ $attributes->merge(['class' => 'font-mono inline-block min-w-0 max-w-full truncate text-gh-text', 'title' => $defaultTitle]) }}>{{ $base }}</span>
@endif

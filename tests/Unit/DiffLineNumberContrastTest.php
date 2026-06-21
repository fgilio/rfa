<?php

declare(strict_types=1);
use Tests\TestCase;

uses(TestCase::class);

/**
 * Guards diff line-number legibility (see resources/CLAUDE.md "Use real muted
 * color, not opacity"). The original bug rendered line numbers at
 * `rgb(var(--gh-muted) / 0.5)` and flooded add/del number cells with the
 * saturated 0.6-alpha gutter stripe, dropping contrast to ~2:1 in both themes.
 *
 * These tests pin the fix two ways:
 *   1. The CSS uses full muted (no opacity divider) for line numbers, and the
 *      add/del cells no longer carry the full-cell stripe fill.
 *   2. The computed WCAG contrast of the line-number color against every
 *      background it actually renders over clears a legibility floor.
 */

/**
 * Parse an "R G B" triple (theme.colors) into [r, g, b].
 *
 * @return array{0: float, 1: float, 2: float}
 */
function rgbTriple(string $value): array
{
    [$r, $g, $b] = array_map('floatval', preg_split('/\s+/', trim($value)));

    return [$r, $g, $b];
}

/**
 * Parse an "rgba(r,g,b,a)" string (theme.raw) into [r, g, b, a].
 *
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function rgbaParts(string $value): array
{
    preg_match('/rgba?\(([^)]+)\)/', $value, $m);
    $parts = array_map('floatval', explode(',', $m[1]));

    return [$parts[0], $parts[1], $parts[2], $parts[3] ?? 1.0];
}

/**
 * Composite a translucent foreground over an opaque background (alpha blend).
 *
 * @param  array{0: float, 1: float, 2: float, 3: float}  $fg
 * @param  array{0: float, 1: float, 2: float}  $bg
 * @return array{0: float, 1: float, 2: float}
 */
function composite(array $fg, array $bg): array
{
    $a = $fg[3];

    return [
        $fg[0] * $a + $bg[0] * (1 - $a),
        $fg[1] * $a + $bg[1] * (1 - $a),
        $fg[2] * $a + $bg[2] * (1 - $a),
    ];
}

/**
 * WCAG 2.x relative luminance for an [r, g, b] colour (0–255 channels).
 *
 * @param  array{0: float, 1: float, 2: float}  $rgb
 */
function relativeLuminance(array $rgb): float
{
    $channel = function (float $c): float {
        $c /= 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
}

/**
 * WCAG contrast ratio between two opaque colours.
 *
 * @param  array{0: float, 1: float, 2: float}  $a
 * @param  array{0: float, 1: float, 2: float}  $b
 */
function contrastRatio(array $a, array $b): float
{
    $la = relativeLuminance($a);
    $lb = relativeLuminance($b);
    [$light, $dark] = [max($la, $lb), min($la, $lb)];

    return ($light + 0.05) / ($dark + 0.05);
}

test('line numbers use full muted, never opacity-on-muted', function () {
    $css = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($css)->toMatch('/\.diff-cell-num\s*\{[^}]*\}/');
    preg_match('/\.diff-cell-num\s*\{([^}]*)\}/', $css, $rule);
    $body = $rule[1];

    // Colour is full muted...
    expect($body)->toContain('color: rgb(var(--gh-muted))');
    // ...with no alpha divider that would re-introduce the washed-out bug.
    expect($body)->not->toMatch('/--gh-muted\)\s*\/\s*[\d.]+/');
});

test('add/del number cells do not bury the digits under the full-cell stripe', function () {
    $line = file_get_contents(resource_path('views/components/diff/line.blade.php'));

    // The saturated gutter fill must be a thin marker bar, not a cell background.
    expect($line)
        ->not->toContain('bg-gh-add-line')
        ->not->toContain('bg-gh-del-line')
        ->toContain('diff-num-marker-add')
        ->toContain('diff-num-marker-del');
});

dataset('themes', ['light', 'dark']);

test('line numbers clear the legibility floor on every background', function (string $theme) {
    $colors = config("theme.colors.$theme");
    $raw = config("theme.raw.$theme");

    $muted = rgbTriple($colors['muted']);
    $bg = rgbTriple($colors['bg']);

    // Context line numbers sit on the plain row background — require AA (4.5:1).
    expect(contrastRatio($muted, $bg))
        ->toBeGreaterThanOrEqual(4.5, "muted on bg ($theme)");

    // Add/del line numbers sit on the faint add-bg/del-bg tint (the saturated
    // stripe is now a marker bar, not a fill). Colored gutter affordance —
    // require at least the WCAG UI/large-text floor (3:1), comfortably above
    // the ~2.4:1 the buried-under-stripe bug produced.
    foreach (['add-bg', 'del-bg'] as $token) {
        $tinted = composite(rgbaParts($raw[$token]), $bg);

        expect(contrastRatio($muted, $tinted))
            ->toBeGreaterThanOrEqual(3.0, "muted on $token ($theme)");
    }
})->with('themes');

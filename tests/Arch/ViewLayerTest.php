<?php

test('blade components do not resolve services directly via app()', function () {
    $viewDir = dirname(__DIR__, 2).'/resources/views';
    $bladeFiles = glob($viewDir.'/{,*/,*/*/}*.blade.php', GLOB_BRACE);

    expect($bladeFiles)->not->toBeEmpty();

    foreach ($bladeFiles as $file) {
        $content = file_get_contents($file);
        $basename = basename($file);

        expect($content)->not->toMatch(
            '/app\s*\(\s*\\\\?App\\\\Services\\\\/',
            "Blade file {$basename} resolves a Service directly via app(). Use an Action instead."
        );
    }
});

/**
 * Find every `@localScript` that sits outside a balanced `@assets` block, plus
 * any unbalanced block boundary, in one pass over Blade source.
 *
 * Blade's `@once` is per-render, so a component whose markup first arrives in a
 * Livewire update payload emits its `<script>` inside that payload — and the
 * browser never executes an injected script tag, leaving the Alpine factory
 * unregistered. Livewire's `@assets` hoists the tag into the head and injects
 * it for update-delivered components, so every component script belongs there.
 *
 * @return list<string> one entry per violation; empty when the source is clean
 */
function assetsBlockViolations(string $source): array
{
    // Blank out Blade comments, newlines included, so prose that names a
    // directive can't move the scanner while reported lines still point at the
    // real source line.
    $scannable = (string) preg_replace_callback(
        '/\{\{--.*?--\}\}/s',
        fn (array $match): string => (string) preg_replace('/[^\n]/', ' ', $match[0]),
        $source,
    );

    // Every directive token in source order, not one per line: an inline
    // `@assets @localScript(...) @endassets` opens and closes on the same line.
    preg_match_all('/@(assets|endassets|localScript)\b/', $scannable, $matches, PREG_OFFSET_CAPTURE);

    $violations = [];
    $depth = 0;
    $openedAtLine = null;

    foreach ($matches[1] as $match) {
        /** @var array{0: string, 1: int} $match */
        [$directive, $offset] = $match;
        $line = substr_count($scannable, "\n", 0, $offset) + 1;

        if ($directive === 'assets') {
            $depth++;
            $openedAtLine ??= $line;

            continue;
        }

        if ($directive === 'endassets') {
            $depth--;

            if ($depth < 0) {
                $violations[] = "line {$line}: @endassets with no open @assets";
                // Clamp, so a stray close can't drive the depth negative and
                // make every later @localScript look enclosed.
                $depth = 0;
            }

            if ($depth === 0) {
                $openedAtLine = null;
            }

            continue;
        }

        if ($depth === 0) {
            $violations[] = "line {$line}: @localScript outside @assets — a Livewire update would inject a <script> the browser never runs";
        }
    }

    if ($depth > 0) {
        $violations[] = "line {$openedAtLine}: @assets is never closed";
    }

    return $violations;
}

test('component scripts load inside a balanced @assets block', function () {
    // Walked recursively rather than globbed: a depth-limited glob pattern
    // would quietly stop covering views once the tree grows another level, and
    // a guard rule that silently skips files is worse than no rule.
    $bladeFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $entry) {
        if (str_ends_with((string) $entry, '.blade.php')) {
            $bladeFiles[] = (string) $entry;
        }
    }

    expect($bladeFiles)->not->toBeEmpty();

    foreach ($bladeFiles as $file) {
        // Layouts are exempt: a layout is always a whole document, never
        // markup delivered inside a Livewire update payload.
        if (str_contains($file, '/layouts/')) {
            continue;
        }

        $violations = assetsBlockViolations((string) file_get_contents($file));

        expect($violations)->toBe([], sprintf(
            "%s\n  %s",
            basename($file),
            implode("\n  ", $violations),
        ));
    }
});

test('the @assets scanner reads directives, not prose', function () {
    // Prose inside a Blade comment must not open a block...
    expect(assetsBlockViolations(<<<'BLADE'
        {{-- Use
        @assets here, not @once. --}}
        @localScript('js/x.js')
        BLADE))->toHaveCount(1);

    // ...nor close one.
    expect(assetsBlockViolations(<<<'BLADE'
        @assets
        {{-- An
        @endassets in prose closes nothing. --}}
        @localScript('js/x.js')
        @endassets
        BLADE))->toBe([]);

    // An inline block opens and closes on one line, so the script after it is
    // outside — the state machine must see both tokens on that line.
    expect(assetsBlockViolations("@assets @localScript('js/x.js') @endassets\n@localScript('js/y.js')"))
        ->toHaveCount(1);

    // An unclosed block is a violation in itself, not a licence for the rest
    // of the file.
    expect(assetsBlockViolations("@assets\n@localScript('js/x.js')"))->toHaveCount(1);

    // A stray close is reported and clamped, so the script after it is still
    // seen as outside.
    expect(assetsBlockViolations("@endassets\n@localScript('js/x.js')"))->toHaveCount(2);

    // The shape every component should have.
    expect(assetsBlockViolations("@assets\n@localScript('js/x.js')\n@endassets"))->toBe([]);
});

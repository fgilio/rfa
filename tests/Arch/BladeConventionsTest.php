<?php

/**
 * Blade template conventions:
 * - flux:icon must use variant="outline" (outline stroke icons, not solid)
 * - flux:button/input/menu.item with icon= must use icon:variant="outline"
 * - No hardcoded class="dark" on <html> (use Flux's @fluxAppearance)
 */
function bladeFiles(): array
{
    $dir = dirname(__DIR__, 2).'/resources/views';
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php' && str_contains($file->getFilename(), '.blade.')) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

function bladeRelativePath(string $path): string
{
    return str_replace(dirname(__DIR__, 2).'/resources/views/', '', $path);
}

function inlineAlpineObjects(string $content): array
{
    preg_match_all('/x-data="\{(.*?)\}"(?=\s|>)/s', $content, $matches, PREG_OFFSET_CAPTURE);

    return collect($matches[1])
        ->map(fn (array $match): array => [
            'body' => $match[0],
            'line' => substr_count(substr($content, 0, $match[1]), "\n") + 1,
        ])
        ->all();
}

test('flux:icon uses outline variant', function () {
    $violations = [];
    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);
        preg_match_all('/(<flux:icon\b[^>]*?>)/s', $content, $matches);
        foreach ($matches[1] as $tag) {
            if (! str_contains($tag, 'variant="outline"')) {
                $violations[] = basename($file).": {$tag}";
            }
        }
    }
    expect($violations)->toBeEmpty();
});

test('flux:button, flux:input and flux:menu.item with icon prop use outline variant', function () {
    $violations = [];
    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);
        preg_match_all('/(<flux:(?:button|input|menu\.item)\b[^>]*?>)/s', $content, $matches);
        foreach ($matches[1] as $tag) {
            if (preg_match('/\bicon="/', $tag) && ! str_contains($tag, 'icon:variant="outline"')) {
                $violations[] = basename($file).": {$tag}";
            }
        }
    }
    expect($violations)->toBeEmpty();
});

test('app layout includes keepalive component', function () {
    $layout = dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php';
    $content = file_get_contents($layout);
    expect($content)->toContain('<livewire:keepalive');
});

test('app layout reloads directly on native hard reload shortcut', function () {
    $layout = dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php';
    $content = file_get_contents($layout);

    expect($content)
        ->toContain("window.Livewire.on('native:App\\\\Events\\\\HardReloadShortcutPressed'")
        ->toContain('window.location.reload()');
});

test('keepalive component uses wire:poll.keep-alive', function () {
    $component = dirname(__DIR__, 2).'/resources/views/livewire/keepalive.blade.php';
    $content = file_get_contents($component);
    expect($content)->toMatch('/wire:poll\.\d+s\.keep-alive/');
});

test('wire:smart-poll always pairs data-focus and data-blur', function () {
    $violations = [];
    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);
        preg_match_all('/<[^>]*\bwire:smart-poll\b[^>]*>/s', $content, $matches);
        foreach ($matches[0] as $tag) {
            $hasFocus = preg_match('/\bdata-focus=/', $tag);
            $hasBlur = preg_match('/\bdata-blur=/', $tag);
            if (! $hasFocus || ! $hasBlur) {
                $violations[] = basename($file).": {$tag}";
            }
        }
    }
    expect($violations)->toBeEmpty();
});

test('no hardcoded dark class on html element', function () {
    $violations = [];
    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);
        preg_match_all('/(<html\b[^>]*?>)/s', $content, $matches);
        foreach ($matches[1] as $tag) {
            if (str_contains($tag, 'class="dark"')) {
                $violations[] = basename($file).": {$tag}";
            }
        }
    }
    expect($violations)->toBeEmpty();
});

test('alpine event names do not collide with blade directives', function () {
    // Blade greedily matches its built-in directives. `@show-remote-menu.window`
    // compiles as a yieldSection() call followed by garbage HTML, silently
    // dropping the listener. Forbid Alpine event names whose first hyphenated
    // segment is a Blade directive that emits PHP regardless of args.
    $riskyDirectives = ['show', 'stop', 'parent', 'csrf', 'verbatim', 'endverbatim', 'once', 'endonce', 'php', 'endphp'];
    $pattern = '/(?:@|x-on:)('.implode('|', $riskyDirectives).')-[a-z]/i';
    $violations = [];
    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[0] as $hit) {
                $violations[] = basename($file).": {$hit}";
            }
        }
    }
    expect($violations)->toBeEmpty();
});

/**
 * Pins the design intent of the review-page softRefresh fingerprint:
 * change-detection must use the raw `mtime` / `byteSize` fields, not
 * the formatted `lastModified` / `fileSize` strings. The latter bucket
 * (`diffForHumans` short-form, `Number::fileSize` precision-1) and
 * caused the 1commit+WC stale-diff bug. If a future refactor reverts
 * the keys, this test fires before the toast count silently degrades.
 */
test('review-page fileFingerprints uses raw mtime and byteSize, not formatted strings', function () {
    $page = dirname(__DIR__, 2).'/resources/views/pages/⚡review-page.blade.php';
    $content = file_get_contents($page);

    if (! preg_match('/private function fileFingerprint\([^)]*\): string\s*\{(.*?)^    \}/sm', $content, $m)) {
        test()->fail('Could not locate fileFingerprint method body');
    }

    $body = $m[1];

    expect($body)
        ->toContain("'mtime'")
        ->toContain("'byteSize'")
        ->not->toContain("'lastModified'")
        ->not->toContain("'fileSize'");
});

test('review-page diff-file keys include per-file refresh fingerprints', function () {
    $page = dirname(__DIR__, 2).'/resources/views/pages/⚡review-page.blade.php';
    $content = file_get_contents($page);
    $totalDiffFiles = substr_count($content, '<livewire:diff-file');

    preg_match_all('/<livewire:diff-file\b.*?\/>/s', $content, $matches);

    expect($matches[0])->toHaveCount($totalDiffFiles);

    foreach ($matches[0] as $tag) {
        expect($tag)->toContain('refreshFingerprint');
    }
});

test('branch explorer active commit state uses ref-aware matching', function () {
    $component = dirname(__DIR__, 2).'/resources/views/livewire/⚡branch-explorer.blade.php';
    $content = file_get_contents($component);

    expect($content)
        ->toContain('isActiveCommit(commit.hash)')
        ->not->toContain('activeCommitHash === commit.hash');
});

test('blade templates do not attach pending-save Livewire hooks inline', function () {
    $violations = [];

    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);

        // Target the pending-save regression only. Other Livewire hooks can be
        // valid when owned by a testable public/js module with explicit cleanup.
        if (preg_match('/Livewire\.hook\([\'"]commit[\'"]/', $content)) {
            $violations[] = bladeRelativePath($file);
        }
    }

    expect($violations)->toBeEmpty();
});

test('blade templates version first-party javascript assets', function () {
    $violations = [];

    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);

        if (preg_match_all('/<script\s+[^>]*src=["\']\/js\/[^"\']+["\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $tag) {
                $violations[] = bladeRelativePath($file).': '.$tag;
            }
        }
    }

    expect($violations)->toBeEmpty();
});

test('inline alpine timers clear themselves on destroy', function () {
    $violations = [];

    foreach (bladeFiles() as $file) {
        foreach (inlineAlpineObjects(file_get_contents($file)) as $object) {
            if (! str_contains($object['body'], 'setInterval(') && ! str_contains($object['body'], 'setTimeout(')) {
                continue;
            }

            if (! str_contains($object['body'], 'destroy()')) {
                $violations[] = bladeRelativePath($file).':'.$object['line'];
            }
        }
    }

    expect($violations)->toBeEmpty();
});

test('diff-file alpine component clears timers on destroy', function () {
    $script = file_get_contents(dirname(__DIR__, 2).'/public/js/diff-file.js');

    expect($script)
        ->toContain('escTimer: null')
        ->toContain('setTimeout(')
        ->toContain('destroy()')
        ->toContain('clearTimeout(this.escTimer)');
});

test('diff image sources use encoded image urls', function () {
    $component = dirname(__DIR__, 2).'/resources/views/livewire/⚡diff-file.blade.php';
    $content = file_get_contents($component);

    expect($content)
        ->toContain('$this->imageUrl(')
        ->not->toContain('src="/api/image/');
});

test('blade templates use gh-* tokens, not stock Tailwind palette colors', function () {
    // The app is monochrome-brutalist: color comes from the gh-* token system
    // (config/theme.php), never stock Tailwind palette utilities. See resources/CLAUDE.md.
    $pattern = '/\b(?:text|bg|border|ring|fill|stroke|divide|from|to|via|outline|decoration|shadow|accent|caret)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)-(?:50|[1-9]00|950)\b/';

    $violations = [];
    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[0] as $hit) {
                $violations[] = bladeRelativePath($file).': '.$hit;
            }
        }
    }

    expect($violations)->toBeEmpty();
});

test('flux components do not use stock palette color props', function () {
    // Flux color="red|green|…" bypasses the gh-* tokens. Use gh-* utilities, or the
    // accent token (aliased to gh-accent in app.css) for primary emphasis.
    $pattern = '/\bcolor="(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)"/';

    $violations = [];
    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[0] as $hit) {
                $violations[] = bladeRelativePath($file).': '.$hit;
            }
        }
    }

    expect($violations)->toBeEmpty();
});

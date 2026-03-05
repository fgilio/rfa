<?php

/**
 * Blade template conventions:
 * - flux:icon must use variant="outline" (outline stroke icons, not solid)
 * - flux:button/input with icon= must use icon:variant="outline"
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

test('flux:button and flux:input with icon prop use outline variant', function () {
    $violations = [];
    foreach (bladeFiles() as $file) {
        $content = file_get_contents($file);
        preg_match_all('/(<flux:(?:button|input)\b[^>]*?>)/s', $content, $matches);
        foreach ($matches[1] as $tag) {
            if (preg_match('/\bicon="/', $tag) && ! str_contains($tag, 'icon:variant="outline"')) {
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

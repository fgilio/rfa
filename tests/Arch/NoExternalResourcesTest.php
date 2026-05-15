<?php

/**
 * No external resources (CDNs, Google Fonts) in blade templates.
 * All assets must be served locally.
 */
function bladeFilesForResources(): array
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

test('no external resources in blade templates', function () {
    $violations = [];
    $pattern = '/<(?:link|script)\b[^>]*(?:href|src)\s*=\s*"(https?:\/\/|\/\/)[^"]*"/i';

    foreach (bladeFilesForResources() as $file) {
        $content = file_get_contents($file);
        preg_match_all($pattern, $content, $matches);
        foreach ($matches[0] as $tag) {
            $violations[] = basename($file).": {$tag}";
        }
    }

    expect($violations)->toBeEmpty();
});

test('app layout uses compiled tailwind css instead of the browser runtime', function () {
    $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php');

    expect($layout)
        ->toContain('href="/build/app.css"')
        ->not->toContain('/js/tailwind.js')
        ->not->toContain('tailwind.config');
});

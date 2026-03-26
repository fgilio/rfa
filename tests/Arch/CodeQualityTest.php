<?php

/**
 * General code quality rules:
 * - No debug functions left in code
 * - Use strict types across all app code
 * - Laravel preset conventions
 * - No suspicious characters in source files
 */
arch('no debug functions in app code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('app code uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('app code has no suspicious characters')
    ->expect('App')
    ->not->toHaveSuspiciousCharacters();

arch()->preset()->php();

arch()->preset()->security();

test('blade files use @js() instead of Js::from()', function () {
    $viewsDir = dirname(__DIR__, 2).'/resources/views';
    $violations = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsDir));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php') && str_contains(file_get_contents($file->getPathname()), 'Js::from')) {
            $violations[] = str_replace($viewsDir.'/', '', $file->getPathname());
        }
    }

    expect($violations)->toBeEmpty('Use @js() instead of Js::from() in: '.implode(', ', $violations));
});

arch('app code uses File facade instead of raw file functions')
    ->expect(['file_exists', 'file_get_contents', 'file_put_contents', 'is_file', 'is_dir', 'unlink', 'mkdir', 'rmdir'])
    ->not->toBeUsedIn('App');

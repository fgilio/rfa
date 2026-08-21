<?php

declare(strict_types=1);

/**
 * Keyboard-shortcut catalog contract. config/shortcuts.php is the single source
 * of truth for every documented shortcut; these rules keep the three consumers
 * (the Alpine shortcuts store, the cheat sheet, the native menu) honest and stop
 * combos from drifting back into hard-coded call sites.
 */

/** @return array{groups: list<string>, shortcuts: array<string, array<string, mixed>>} */
function shortcutsConfig(): array
{
    return require dirname(__DIR__, 2).'/config/shortcuts.php';
}

/** @return list<string> */
function shortcutFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['resources/views', 'public/js'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['php', 'js'], true)) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

test('every shortcut entry has a combo, label, and known group', function () {
    $config = shortcutsConfig();
    $groups = $config['groups'];

    expect($groups)->toBeArray()->not->toBeEmpty();

    foreach ($config['shortcuts'] as $id => $entry) {
        expect($entry['combo'] ?? '')->toBeString()->not->toBe('', "[$id] needs a combo");
        expect($entry['label'] ?? '')->toBeString()->not->toBe('', "[$id] needs a label");
        expect($entry['group'] ?? null)->toBeIn($groups, "[$id] group must be one of the declared groups");
    }
});

test('native and menu shortcuts declare an Electron accelerator', function () {
    foreach (shortcutsConfig()['shortcuts'] as $id => $entry) {
        if (($entry['wired'] ?? null) === 'native') {
            expect($entry['accelerator'] ?? '')->toBeString()->not->toBe('', "[$id] is native and needs an accelerator");
        }
    }
});

test('every shortcuts-store id used in views and scripts exists in the catalog', function () {
    $ids = array_keys(shortcutsConfig()['shortcuts']);

    foreach (shortcutFiles() as $file) {
        $contents = file_get_contents($file);

        preg_match_all(
            "/shortcuts\.(?:register|unregister|combo|display)\(\s*['\"]([^'\"]+)['\"]/",
            $contents,
            $matches,
        );

        foreach ($matches[1] as $id) {
            expect(in_array($id, $ids, true))->toBeTrue("$file references unknown shortcut id \"$id\"");
        }
    }
});

test('every Shortcuts accessor id referenced in PHP exists in the catalog', function () {
    $ids = array_keys(shortcutsConfig()['shortcuts']);

    $files = [
        ...shortcutFiles(),
        dirname(__DIR__, 2).'/app/Providers/NativeAppServiceProvider.php',
    ];

    foreach ($files as $file) {
        $contents = file_get_contents($file);

        preg_match_all(
            "/Shortcuts::(?:combo|display|accelerator)\(\s*'([^']+)'/",
            $contents,
            $matches,
        );

        foreach ($matches[1] as $id) {
            expect(in_array($id, $ids, true))->toBeTrue("$file references unknown shortcut id \"$id\"");
        }
    }
});

test('the ids the app wires against are present in the catalog', function () {
    // Spelled out because some call sites alias the store (`const s = $store.shortcuts`)
    // and so escape the textual scans above. Renaming an id in the catalog without
    // updating these breaks a real shortcut.
    $ids = array_keys(shortcutsConfig()['shortcuts']);

    $required = [
        'project-picker.toggle', 'comments-drawer.toggle', 'branch-explorer.toggle',
        'review.filter', 'review.next-file', 'review.prev-file',
        'review.collapse-all', 'review.expand-all', 'review.prev-commit', 'review.next-commit',
        'comment.save', 'review.undo',
        'sidebar.toggle',
        'app.refresh', 'app.hard-reload', 'app.add-repo', 'app.context-files', 'app.review-code',
        'help.shortcuts',
    ];

    foreach ($required as $id) {
        expect(in_array($id, $ids, true))->toBeTrue("catalog is missing required shortcut id \"$id\"");
    }
});

test('keyboard registration goes through the shortcuts store, never keymap directly', function () {
    foreach (shortcutFiles() as $file) {
        // keymap-store.js *defines* register/unregister; shortcuts-store.js is the
        // one allowed caller (via `keymap().register`). Everything else must route
        // through `$store.shortcuts` so the combo comes from the catalog.
        if (str_ends_with($file, 'keymap-store.js') || str_ends_with($file, 'shortcuts-store.js')) {
            continue;
        }

        $contents = file_get_contents($file);

        // Match both `keymap.register(` and the `keymap().register(` accessor form
        // so neither can bypass the guard from a non-store file.
        expect($contents)
            ->not->toMatch('/\bkeymap(?:\(\))?\s*\.\s*register\s*\(/')
            ->not->toMatch('/\bkeymap(?:\(\))?\s*\.\s*unregister\s*\(/');
    }
});

test('layout injects the catalog and loads the shortcuts store', function () {
    $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php');

    expect($layout)
        ->toContain('window.RFA_SHORTCUTS')
        ->toContain('Shortcuts::all()')
        ->toContain("@localScript('js/shortcuts-store.js')")
        ->toContain('<x-shortcuts-help />');
});

test('native menu hotkeys are sourced from the catalog', function () {
    $provider = file_get_contents(dirname(__DIR__, 2).'/app/Providers/NativeAppServiceProvider.php');

    expect($provider)
        ->toContain("Shortcuts::accelerator('app.add-repo')")
        ->toContain("Shortcuts::accelerator('app.context-files')")
        ->toContain("Shortcuts::accelerator('app.review-code')");
});

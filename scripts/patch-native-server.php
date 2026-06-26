<?php

/**
 * Patch NativePHP's Electron server bootstrap to skip the expensive part of
 * `php artisan optimize` on same-version launches.
 *
 * NativePHP runs `php artisan optimize` synchronously before the PHP server
 * starts, on every launch. That recompiles all Blade views (~1s) and blocks the
 * window from appearing. The compiled views persist in userData and self-heal
 * via on-demand compilation, so the full optimize is only needed when the app
 * version changes (fresh install / post-update).
 *
 * The catch: NativePHP injects a fresh per-launch API port and secret that PHP
 * reads through `config()`, so the *config* cache must still be rebuilt every
 * launch or the PHP server would talk to a stale port and the native bridge
 * would break. The patch therefore runs the full `optimize` only on a version
 * change (or when the route/event caches are missing) and a cheap `config:cache`
 * otherwise.
 *
 * Runs automatically via composer post-autoload-dump. We patch the compiled
 * `dist/server/php.js` because `electron-vite build` bundles that file directly
 * (it does not run the plugin's `tsc` step).
 */

/**
 * @return 'patched'|'already_patched'|'block_not_found'|'not_found'
 */
function patchNativeServerOptimize(string $serverPath): string
{
    if (! file_exists($serverPath)) {
        return 'not_found';
    }

    $content = file_get_contents($serverPath);

    if (str_contains($content, 'rfaNeedsFullOptimize')) {
        return 'already_patched';
    }

    $original = <<<'JS'
        if (shouldOptimize(store)) {
            console.log('Caching view and routes...');
            let result = callPhpSync(['artisan', 'optimize'], phpOptions, phpIniSettings);
            if (result.status !== 0) {
                console.error('Failed to cache view and routes:', result.stderr.toString());
            }
            else {
                store.set('optimized_version', app.getVersion());
            }
        }
JS;

    if (! str_contains($content, $original)) {
        return 'block_not_found';
    }

    $replacement = <<<'JS'
        if (shouldOptimize(store)) {
            // [rfa patch] `php artisan optimize` recompiles every Blade view
            // (~1s) and previously ran on every launch, blocking the window.
            // Compiled views persist in userData and self-heal via on-demand
            // compilation, so the full optimize is only needed when the app
            // version changes (fresh install / post-update) or the route/event
            // caches are missing. On same-version launches we re-cache config
            // alone: NativePHP injects a fresh per-launch API port and secret
            // that PHP reads through config(), so a reused config cache would
            // point the PHP server at a stale port and break the native bridge.
            const rfaVersionChanged = store.get('optimized_version') !== app.getVersion();
            const rfaNeedsFullOptimize = rfaVersionChanged
                || !existsSync(join(bootstrapCache, 'routes-v7.php'))
                || !existsSync(join(bootstrapCache, 'events.php'));
            const rfaCommand = rfaNeedsFullOptimize ? 'optimize' : 'config:cache';
            console.log(rfaNeedsFullOptimize ? 'Caching views, routes, and config...' : 'Refreshing config cache...');
            let result = callPhpSync(['artisan', rfaCommand], phpOptions, phpIniSettings);
            if (result.status !== 0) {
                console.error('Failed to cache framework bootstrap:', result.stderr.toString());
            }
            else if (rfaNeedsFullOptimize) {
                store.set('optimized_version', app.getVersion());
            }
        }
JS;

    file_put_contents($serverPath, str_replace($original, $replacement, $content));

    return 'patched';
}

// Run when executed directly (not when required by tests)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $serverPath = __DIR__.'/../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js';

    $result = patchNativeServerOptimize($serverPath);

    match ($result) {
        'patched' => print "  NativePHP server patched: optimize runs once per version.\n",
        'already_patched' => print "  NativePHP server already patched (optimize).\n",
        'block_not_found' => fwrite(STDERR, "  WARNING: NativePHP optimize block not found. Patch skipped.\n"),
        'not_found' => null,
    };
}

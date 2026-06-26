<?php

/**
 * Patch NativePHP's Electron server bootstrap for faster cold starts.
 *
 * Two independent edits to the compiled `dist/server/php.js` (the file
 * `electron-vite build` bundles directly — it does not run the plugin's `tsc`
 * step):
 *
 *  1. Optimize once per version. NativePHP runs `php artisan optimize`
 *     synchronously before the PHP server starts, on every launch. That
 *     recompiles all Blade views (~1s) and blocks the window. Compiled views
 *     persist in userData and self-heal via on-demand compilation, so the full
 *     optimize is only needed on a version change (fresh install / post-update)
 *     or when the route/event caches are missing. On same-version launches we
 *     re-cache config alone, because NativePHP injects a fresh per-launch API
 *     port and secret that PHP reads through config() — a reused config cache
 *     would point the PHP server at a stale port and break the native bridge.
 *
 *  2. Warm opcode for the launch-time `php artisan` calls. NativePHP shells out
 *     to `native:php-ini` and `native:config` before the server starts, each a
 *     full framework boot (~210ms) with no opcache (these calls don't receive
 *     the app's phpIni()). Point them at a persistent opcache file cache so they
 *     reuse opcode compiled on earlier launches (~210ms -> ~120ms here), and
 *     create that cache directory at startup so it exists before the first call.
 *     (The long-lived server and the optimize/migrate calls already get opcache
 *     via NativeAppServiceProvider::phpIni().)
 *
 * Each edit is applied idempotently and independently; a NativePHP bump that
 * reshapes one block leaves the others intact. Runs automatically via composer
 * post-autoload-dump.
 *
 * @return 'patched'|'already_patched'|'block_not_found'|'not_found'
 */
function patchNativeServerOptimize(string $serverPath): string
{
    if (! file_exists($serverPath)) {
        return 'not_found';
    }

    $content = file_get_contents($serverPath);

    $optimizeFind = <<<'JS'
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

    $optimizeReplace = <<<'JS'
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

    // Create the opcache file-cache directory at module load, before any PHP
    // call runs. opcache will not create it itself, and the pre-flight calls
    // below need it to already exist.
    $mkdirFind = "mkdirpSync(join(storagePath, 'framework', 'testing'));";
    $mkdirReplace = "mkdirpSync(join(storagePath, 'framework', 'testing'));\n".
        "mkdirpSync(join(storagePath, 'framework', 'opcache')); // [rfa opcache] persistent opcode cache dir";

    // Prepend opcache flags to the pre-flight artisan calls so a short-lived
    // boot reuses opcode from the file cache instead of recompiling from source.
    $preflightFind = <<<'JS'
        if (runningSecureBuild()) {
            command.unshift(join(appPath, 'build', '__nativephp_app_bundle'));
        }
        return yield promisify(execFile)(state.php, command, phpOptions);
JS;

    $preflightReplace = <<<'JS'
        if (runningSecureBuild()) {
            command.unshift(join(appPath, 'build', '__nativephp_app_bundle'));
        }
        // [rfa opcache] reuse compiled opcode across launches (~210ms -> ~120ms)
        command.unshift('-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=1', '-d', `opcache.file_cache=${join(storagePath, 'framework', 'opcache')}`);
        return yield promisify(execFile)(state.php, command, phpOptions);
JS;

    $applied = 0;

    if (str_contains($content, $optimizeFind)) {
        $content = str_replace($optimizeFind, $optimizeReplace, $content);
        $applied++;
    }

    if (str_contains($content, $mkdirFind) && ! str_contains($content, "'framework', 'opcache'")) {
        $content = str_replace($mkdirFind, $mkdirReplace, $content);
        $applied++;
    }

    // Both pre-flight functions share this exact tail; replace_all handles both.
    if (str_contains($content, $preflightFind)) {
        $content = str_replace($preflightFind, $preflightReplace, $content);
        $applied++;
    }

    if ($applied > 0) {
        file_put_contents($serverPath, $content);

        return 'patched';
    }

    // Nothing left to apply: either fully patched already, or the file no longer
    // matches any expected block (NativePHP changed shape).
    if (str_contains($content, 'rfaNeedsFullOptimize')) {
        return 'already_patched';
    }

    return 'block_not_found';
}

// Run when executed directly (not when required by tests)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $serverPath = __DIR__.'/../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js';

    $result = patchNativeServerOptimize($serverPath);

    match ($result) {
        'patched' => print "  NativePHP server patched: optimize once per version + opcache warm boots.\n",
        'already_patched' => print "  NativePHP server already patched (optimize + opcache).\n",
        'block_not_found' => fwrite(STDERR, "  WARNING: NativePHP server blocks not found. Patch skipped.\n"),
        'not_found' => null,
    };
}

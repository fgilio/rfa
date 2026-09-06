<?php

/**
 * The NativePHP vendor changes RFA depends on, applied as one patch set.
 *
 * The edits across `nativephp/desktop`'s bundled Electron app are not
 * independent in practice: four of them rewrite
 * the same `dist/index.js`, and a build that ships some of them is a build
 * whose startup behaviour nobody has tested. So the set is all-or-nothing —
 * every expected source shape is checked, and every new file content computed,
 * before the first byte is written. If any edit no longer matches, nothing is
 * written at all and the composer hook fails.
 *
 * Runs automatically via composer post-autoload-dump and post-update-cmd.
 */
/** @return array{int, int, int} */
function rfaParseThemeRgb(mixed $value, string $key): array
{
    if (! is_string($value)
        || preg_match('/^(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})$/', trim($value), $matches) !== 1) {
        throw new RuntimeException("Theme color [{$key}] must be an RGB triple.");
    }

    $channels = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];

    if (max($channels) > 255) {
        throw new RuntimeException("Theme color [{$key}] must contain channels from 0 to 255.");
    }

    return $channels;
}

/** @return array{int, int, int} */
function rfaThemeRgb(string $appearance, string $token): array
{
    /** @var array<string, mixed>|null $colors */
    static $colors;

    if ($colors === null) {
        $theme = require __DIR__.'/../config/theme.php';
        $colors = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];
    }

    $key = "colors.{$appearance}.{$token}";
    $appearanceColors = $colors[$appearance] ?? null;
    $value = is_array($appearanceColors) ? ($appearanceColors[$token] ?? null) : null;

    return rfaParseThemeRgb($value, $key);
}

function rfaThemeHex(string $appearance, string $token): string
{
    return sprintf('#%02x%02x%02x', ...rfaThemeRgb($appearance, $token));
}

function rfaThemeRgba(string $appearance, string $token, string $alpha): string
{
    return 'rgba('.implode(',', rfaThemeRgb($appearance, $token)).','.$alpha.')';
}

function rfaBackgroundExpression(): string
{
    return "nativeTheme.shouldUseDarkColors ? '".rfaThemeHex('dark', 'bg')."' : '".rfaThemeHex('light', 'bg')."'";
}

/**
 * Expose webUtils.getPathForFile() from NativePHP's Electron preload.
 *
 * Electron 38+ removed File.path from the renderer. The replacement,
 * webUtils.getPathForFile(), is only available in the preload context.
 * NativePHP's preload doesn't expose it, so we patch the compiled JS
 * to bridge it via contextBridge.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchPreloadFileBridge(string $content): ?string
{
    if (str_contains($content, "exposeInMainWorld('nativeGetFilePath'")) {
        // Already bridged. The exposure is useless without webUtils on the
        // import, so a half-applied file counts as a shape change, not a hit.
        return str_contains($content, 'webUtils') ? $content : null;
    }

    // Add webUtils to the electron import
    $original = $content;
    $content = str_replace(
        'import { ipcRenderer, contextBridge } from "electron";',
        'import { ipcRenderer, contextBridge, webUtils } from "electron";',
        $content
    );

    if ($content === $original) {
        return null;
    }

    // Expose getPathForFile to the renderer via contextBridge
    $content .= <<<'JS'

// [rfa patch] Expose webUtils.getPathForFile for drag-and-drop support.
// File objects pass through contextBridge via structured cloning.
contextBridge.exposeInMainWorld('nativeGetFilePath', (file) => webUtils.getPathForFile(file));
JS;

    return $content."\n";
}

/**
 * Expose one safe renderer-to-main readiness signal from the preload script.
 *
 * The renderer does not receive Electron's ipcRenderer directly. It can only
 * send this fixed channel with no caller-controlled name or payload.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchPreloadRendererReady(string $content): ?string
{
    $anchor = "contextBridge.exposeInMainWorld('Native', Native);";
    $marker = <<<'JS'

// [rfa renderer readiness] Expose one fixed, payload-free readiness signal.
contextBridge.exposeInMainWorld('nativeRendererReady', () => ipcRenderer.send('rfa:renderer-ready'));
JS;

    if (str_contains($content, $anchor) && ! str_contains($content, "exposeInMainWorld('nativeRendererReady'")) {
        $content = str_replace($anchor, $anchor.$marker, $content);
    }

    $fullyPatched = str_contains($content, '[rfa renderer readiness]')
        && str_contains($content, "ipcRenderer.send('rfa:renderer-ready')");

    return $fullyPatched ? $content : null;
}

/**
 * Give every NativePHP BrowserWindow the resolved RFA background color.
 *
 * The renderer and splash both use RFA's light and dark background tokens.
 * NativePHP otherwise uses the value sent by PHP, which can disagree with the
 * persisted renderer appearance during the frame before HTML is presented.
 * The main window starts transparent because `electron-window-state` restores
 * maximization with `maximize()`, which implicitly shows a hidden window.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchWindowTheme(string $content): ?string
{
    $importFind = "import { BrowserWindow } from 'electron';";
    $importReplace = "import { BrowserWindow, nativeTheme } from 'electron'; // [rfa window theme]";
    $backgroundFind = '        backgroundColor, transparent: transparency, alwaysOnTop,';
    $previousBackgroundReplace = '        backgroundColor: '.rfaBackgroundExpression().', transparent: transparency, alwaysOnTop,';
    $backgroundReplace = '        backgroundColor: '.rfaBackgroundExpression().", opacity: id === 'main' ? 0 : 1, transparent: transparency, alwaysOnTop,";

    if (str_contains($content, $importFind)) {
        $content = str_replace($importFind, $importReplace, $content);
    }

    if (str_contains($content, $backgroundFind)) {
        $content = str_replace($backgroundFind, $backgroundReplace, $content);
    }

    if (str_contains($content, $previousBackgroundReplace)) {
        $content = str_replace($previousBackgroundReplace, $backgroundReplace, $content);
    }

    $fullyPatched = str_contains($content, '[rfa window theme]')
        && str_contains($content, $backgroundReplace)
        && str_contains($content, "opacity: id === 'main' ? 0 : 1")
        && ! str_contains($content, $backgroundFind);

    return $fullyPatched ? $content : null;
}

/**
 * Keep the main window invisible until Electron and the renderer are both ready.
 *
 * `ready-to-show` confirms Electron's first frame. The renderer signal confirms
 * Livewire, Alpine, visible lazy shells, and fonts have settled. A presentation
 * event after that signal confirms Chromium has submitted the settled frame.
 * A timeout releases the renderer and presentation sides of the barrier, so
 * Electron still never shows a window before its own first paint. Repeat open
 * and explicit show requests use the same per-window barrier.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchRendererReadyWindow(string $content): ?string
{
    $find = <<<'JS'
    window.webContents.on('did-finish-load', () => {
        if (state.noFocusOnRestart && window.isVisible()) {
            return;
        }
        window.show();
    });
JS;

    $replace = <<<'JS'
    // [rfa window readiness] Electron first paint and renderer stability form
    // one barrier for the main window. Other windows need first paint only.
    // electron-window-state can implicitly show the main window while restoring
    // maximization. Its opacity barrier replaces ready-to-show for that window.
    // The renderer waits 4s; this outer timeout leaves 1s for the IPC handoff.
    const rfaReadinessTimeoutMs = 5000;
    let rfaPresentationPhase = id === 'main' ? 'waiting-renderer' : 'waiting-paint';
    let rfaFocusWhenReady = false;
    let rfaReadinessTimer = null;
    let rfaRendererMessageListener = null;
    const rfaCleanupReadiness = () => {
        if (rfaReadinessTimer !== null) {
            clearTimeout(rfaReadinessTimer);
            rfaReadinessTimer = null;
        }
        // A window closed before it presented has already torn down its
        // webContents; touching it throws "Object has been destroyed".
        if (rfaRendererMessageListener !== null && !window.isDestroyed()) {
            window.webContents.removeListener('ipc-message', rfaRendererMessageListener);
        }
        rfaRendererMessageListener = null;
    };
    const rfaPresent = () => {
        if (rfaPresentationPhase === 'presented' || rfaPresentationPhase === 'closed') {
            return;
        }
        rfaPresentationPhase = 'presented';
        rfaCleanupReadiness();
        if (id === 'main') {
            window.setOpacity(1);
        }
        if (state.noFocusOnRestart && window.isVisible()) {
            rfaFocusWhenReady = false;
            if (id === 'main') {
                window.emit('rfa:presented');
            }
            return;
        }
        window.show();
        if (rfaFocusWhenReady) {
            window.focus();
        }
        rfaFocusWhenReady = false;
        if (id === 'main') {
            window.emit('rfa:presented');
        }
    };
    window.rfaRequestShow = (focus = false) => {
        if (rfaPresentationPhase === 'closed') {
            return;
        }
        if (rfaPresentationPhase === 'presented') {
            if (state.noFocusOnRestart && window.isVisible()) {
                return;
            }
            window.show();
            if (focus) {
                window.focus();
            }
            return;
        }
        rfaFocusWhenReady = rfaFocusWhenReady || focus;
    };
    if (id === 'main') {
        // The renderer signals after its settled DOM has been painted for two
        // further frames (renderer-ready.js), so the window is shown at once.
        rfaRendererMessageListener = (_, channel) => {
            if (channel !== 'rfa:renderer-ready' || rfaPresentationPhase !== 'waiting-renderer') {
                return;
            }
            rfaPresent();
        };
        window.webContents.on('ipc-message', rfaRendererMessageListener);
        rfaReadinessTimer = setTimeout(rfaPresent, rfaReadinessTimeoutMs);
    } else {
        window.once('ready-to-show', rfaPresent);
    }
    window.once('closed', () => {
        rfaPresentationPhase = 'closed';
        rfaCleanupReadiness();
    });
JS;

    // The readiness block as the PREVIOUS RFA revision left it. It captured a
    // full frame through beginFrameSubscription before presenting (a bitmap
    // copy of the whole window, ~200ms on a Retina display) and touched a
    // destroyed webContents when the window closed before presenting.
    $previousReplace = <<<'JS'
    // [rfa window readiness] Electron first paint and renderer stability form
    // one barrier for the main window. Other windows need first paint only.
    // electron-window-state can implicitly show the main window while restoring
    // maximization. Its opacity barrier replaces ready-to-show for that window.
    // The renderer waits 4s; this outer timeout leaves 1s for the IPC/frame handoff.
    const rfaReadinessTimeoutMs = 5000;
    let rfaPresentationPhase = id === 'main' ? 'waiting-renderer' : 'waiting-paint';
    let rfaFocusWhenReady = false;
    let rfaReadinessTimer = null;
    let rfaRendererMessageListener = null;
    let rfaFrameSubscriptionActive = false;
    const rfaCleanupReadiness = () => {
        if (rfaReadinessTimer !== null) {
            clearTimeout(rfaReadinessTimer);
            rfaReadinessTimer = null;
        }
        if (rfaRendererMessageListener !== null) {
            window.webContents.removeListener('ipc-message', rfaRendererMessageListener);
            rfaRendererMessageListener = null;
        }
        if (rfaFrameSubscriptionActive) {
            window.webContents.endFrameSubscription();
            rfaFrameSubscriptionActive = false;
        }
    };
    const rfaPresent = () => {
        if (rfaPresentationPhase === 'presented' || rfaPresentationPhase === 'closed') {
            return;
        }
        rfaPresentationPhase = 'presented';
        rfaCleanupReadiness();
        if (id === 'main') {
            window.setOpacity(1);
        }
        if (state.noFocusOnRestart && window.isVisible()) {
            rfaFocusWhenReady = false;
            if (id === 'main') {
                window.emit('rfa:presented');
            }
            return;
        }
        window.show();
        if (rfaFocusWhenReady) {
            window.focus();
        }
        rfaFocusWhenReady = false;
        if (id === 'main') {
            window.emit('rfa:presented');
        }
    };
    const rfaWaitForPresentedFrame = () => {
        try {
            window.webContents.beginFrameSubscription(false, () => {
                if (rfaPresentationPhase !== 'waiting-frame') {
                    return;
                }
                window.webContents.endFrameSubscription();
                rfaFrameSubscriptionActive = false;
                rfaPresent();
            });
            rfaFrameSubscriptionActive = true;
            window.webContents.invalidate();
        }
        catch (rfaError) {
            rfaPresent();
        }
    };
    window.rfaRequestShow = (focus = false) => {
        if (rfaPresentationPhase === 'closed') {
            return;
        }
        if (rfaPresentationPhase === 'presented') {
            if (state.noFocusOnRestart && window.isVisible()) {
                return;
            }
            window.show();
            if (focus) {
                window.focus();
            }
            return;
        }
        rfaFocusWhenReady = rfaFocusWhenReady || focus;
    };
    if (id === 'main') {
        rfaRendererMessageListener = (_, channel) => {
            if (channel !== 'rfa:renderer-ready' || rfaPresentationPhase !== 'waiting-renderer') {
                return;
            }
            rfaPresentationPhase = 'waiting-frame';
            rfaWaitForPresentedFrame();
        };
        window.webContents.on('ipc-message', rfaRendererMessageListener);
        rfaReadinessTimer = setTimeout(rfaPresent, rfaReadinessTimeoutMs);
    } else {
        window.once('ready-to-show', rfaPresent);
    }
    window.once('closed', () => {
        rfaPresentationPhase = 'closed';
        rfaCleanupReadiness();
    });
JS;

    if (str_contains($content, $find)) {
        $content = str_replace($find, $replace, $content);
    } elseif (str_contains($content, $previousReplace)) {
        $content = str_replace($previousReplace, $replace, $content);
    }

    $showFind = <<<'JS'
router.post('/show', (req, res) => {
    const { id } = req.body;
    if (state.windows[id]) {
        state.windows[id].show();
    }
    res.sendStatus(200);
});
JS;

    $showReplace = <<<'JS'
router.post('/show', (req, res) => {
    const { id } = req.body;
    const window = state.windows[id];
    if (window) {
        if (typeof window.rfaRequestShow === 'function') {
            window.rfaRequestShow();
        } else {
            window.show();
        }
    }
    res.sendStatus(200);
});
JS;

    if (str_contains($content, $showFind)) {
        $content = str_replace($showFind, $showReplace, $content);
    }

    $existingFind = <<<'JS'
    if (state.windows[id]) {
        state.windows[id].show();
        state.windows[id].focus();
        res.sendStatus(200);
        return;
    }
JS;

    $existingReplace = <<<'JS'
    const existingWindow = state.windows[id];
    if (existingWindow) {
        if (typeof existingWindow.rfaRequestShow === 'function') {
            existingWindow.rfaRequestShow(true);
        } else {
            existingWindow.show();
            existingWindow.focus();
        }
        res.sendStatus(200);
        return;
    }
JS;

    if (str_contains($content, $existingFind)) {
        $content = str_replace($existingFind, $existingReplace, $content);
    }

    $fullyPatched = str_contains($content, $replace)
        && str_contains($content, $showReplace)
        && str_contains($content, $existingReplace)
        && ! str_contains($content, $find)
        && ! str_contains($content, $previousReplace)
        && ! str_contains($content, $showFind)
        && ! str_contains($content, $existingFind);

    return $fullyPatched ? $content : null;
}

/**
 * Speed up cold starts in NativePHP's Electron server bootstrap.
 *
 * Two independent edits to the compiled `dist/server/php.js` (the file
 * `electron-vite build` bundles directly — it does not run the plugin's `tsc`
 * step):
 *
 *  1. Optimize once per version, in the background, and skip the cache step
 *     on warm launches. NativePHP runs `php artisan optimize` synchronously
 *     before the PHP server starts, on every launch. That recompiles all Blade
 *     views and re-caches config/routes/events (~1.7s) and blocks the window.
 *     The compiled caches persist in the build's bootstrap/cache, so the
 *     rebuild is only needed on a version change (fresh install / post-update)
 *     or when a cache file is missing, and then it runs as `rfa:optimize` in a
 *     background child while the server serves from source: the three cache
 *     files are written to a staging directory and renamed into place so no
 *     request ever reads a torn file, and views are compiled without clearing
 *     the live directory. On same-version launches the cache step is skipped
 *     ENTIRELY: the only per-launch-varying config (the native API port and IPC
 *     secret) is re-read from the live process environment at runtime by
 *     RehydrateNativeRuntimeConfigAction, so the persisted config stays valid
 *     without a per-launch config:cache boot.
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
 * Every edit must land: this returns null unless each of them is present in
 * the result, so a NativePHP bump that reshapes one block fails the patch
 * set rather than shipping a half-optimized bootstrap.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchServerOptimize(string $content): ?string
{
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
            // [rfa patch] `php artisan optimize` recompiles every Blade view and
            // re-caches config/routes/events (~1.7s). Stock NativePHP ran it on
            // every launch, blocking the window. The compiled caches persist in
            // the build's bootstrap/cache, so it is only needed when the app
            // version changes (fresh install / post-update) or a cache file is
            // missing, and then it runs in the background while the PHP server
            // starts and serves: the framework boots from source until the
            // caches land, a few ms per request, instead of holding the window
            // for the whole optimize.
            //
            // On a same-version launch the cache step is skipped ENTIRELY,
            // including config:cache for the fresh per-launch API port and IPC
            // secret: the app re-reads those two values from the live process
            // environment at runtime (RehydrateNativeRuntimeConfigAction, wired
            // in bootstrap/app.php via a beforeBootstrapping(RegisterProviders)
            // hook that runs before any provider registers), so the persisted
            // version-cached config stays valid.
            //
            // Probe the caches at the directory Laravel actually writes them to
            // for this build type. NativePHP only redirects APP_*_CACHE into
            // userData/bootstrap/cache for a *secure* build; an unsecure build
            // (what `native:build` produces without a bundle, RFA's shipping
            // shape) leaves them at <appPath>/bootstrap/cache. Checking
            // bootstrapCache unconditionally would never find them in an unsecure
            // build, so the gate would trip every launch and pay the full optimize.
            const rfaCacheDir = runningSecureBuild() ? bootstrapCache : join(getAppPath(), 'bootstrap', 'cache');
            const rfaVersionChanged = store.get('optimized_version') !== app.getVersion();
            const rfaNeedsFullOptimize = rfaVersionChanged
                || !existsSync(join(rfaCacheDir, 'config.php'))
                || !existsSync(join(rfaCacheDir, 'routes-v7.php'))
                || !existsSync(join(rfaCacheDir, 'events.php'));
            if (rfaNeedsFullOptimize) {
                rfaOptimizeInBackground(rfaCacheDir, rfaVersionChanged, phpOptions, phpIniSettings, () => {
                    store.set('optimized_version', app.getVersion());
                });
            }
        }
JS;

    // The background runner lives next to serveApp() so the optimize block
    // stays a gate and a call. It relies on php.js's own imports (fs_extra,
    // mkdirpSync, join, app, callPhp) so no import line changes.
    $helperFind = 'function serveApp(secret, apiPort, phpIniSettings) {';
    $helperMarker = 'function rfaOptimizeInBackground(';
    $helperReplace = <<<'JS'
// [rfa patch] The framework caches, rebuilt without blocking the launch.
//
// Laravel writes config.php, routes-v7.php, and events.php with a plain
// file_put_contents and the server `require`s them on every request, so a
// request landing mid-write would parse a torn file. The child therefore
// writes them into a staging directory, and each is renamed into place with
// one atomic rename once the child exits cleanly. Compiled views go straight
// to the live directory: Blade writes those atomically and skips unchanged
// ones, and rfa:optimize never clears the directory the running server reads
// (view:cache does, which is why plain `optimize` is not used here).
//
// After a version change the caches left by the previous version are removed
// before the server spawns, so the new code never boots against them. That
// includes the compiled views: Blade and Livewire both keep a compiled file
// while it is newer than its source, and a build's sources predate whatever
// the previous version compiled, so a stale view would otherwise be served
// for as long as it is not recompiled.
const rfaStagedCaches = {
    APP_CONFIG_CACHE: 'config.php',
    APP_ROUTES_CACHE: 'routes-v7.php',
    APP_EVENTS_CACHE: 'events.php',
};
function rfaOptimizeInBackground(cacheDir, versionChanged, phpOptions, phpIniSettings, onOptimized) {
    const stagingDir = join(cacheDir, 'rfa-staging');
    const env = Object.assign({}, phpOptions.env);
    try {
        fs_extra.removeSync(stagingDir);
        mkdirpSync(stagingDir);
        Object.keys(rfaStagedCaches).forEach((key) => {
            env[key] = join(stagingDir, rfaStagedCaches[key]);
            if (versionChanged) {
                fs_extra.removeSync(join(cacheDir, rfaStagedCaches[key]));
            }
        });
        if (versionChanged) {
            fs_extra.emptyDirSync(join(storagePath, 'framework', 'views'));
        }
    }
    catch (error) {
        console.error('Failed to prepare the framework cache staging directory:', error);
        return;
    }
    console.log('Caching views, routes, and config in the background...');
    globalThis.__rfaLaunchMark?.('php.optimize.started');
    const child = callPhp(['artisan', 'rfa:optimize'], { cwd: phpOptions.cwd, env }, phpIniSettings);
    let stderr = '';
    child.stdout.on('data', () => { });
    child.stderr.on('data', (data) => { stderr += data.toString(); });
    const stopWithApp = () => child.kill();
    app.once('before-quit', stopWithApp);
    child.on('error', (error) => {
        app.removeListener('before-quit', stopWithApp);
        console.error('Failed to start the framework cache rebuild:', error);
    });
    child.on('exit', (code) => {
        app.removeListener('before-quit', stopWithApp);
        globalThis.__rfaLaunchMark?.('php.optimize.finished');
        if (code !== 0) {
            if (code !== null) {
                console.error('Failed to cache framework bootstrap:', stderr);
            }
            return;
        }
        try {
            Object.values(rfaStagedCaches).forEach((file) => {
                fs_extra.renameSync(join(stagingDir, file), join(cacheDir, file));
            });
            fs_extra.removeSync(stagingDir);
            onOptimized();
        }
        catch (error) {
            console.error('Failed to install the framework caches:', error);
        }
    });
}
function serveApp(secret, apiPort, phpIniSettings) {
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

    $patched = $content;

    if (str_contains($patched, $optimizeFind)) {
        $patched = str_replace($optimizeFind, $optimizeReplace, $patched);
    }

    if (str_contains($patched, $helperFind) && ! str_contains($patched, $helperMarker)) {
        $patched = str_replace($helperFind, $helperReplace, $patched);
    }

    if (str_contains($patched, $mkdirFind) && ! str_contains($patched, '[rfa opcache] persistent opcode cache dir')) {
        $patched = str_replace($mkdirFind, $mkdirReplace, $patched);
    }

    // Both pre-flight functions share this exact tail; str_replace handles both.
    if (str_contains($patched, $preflightFind)) {
        $patched = str_replace($preflightFind, $preflightReplace, $patched);
    }

    // Only report success when every edit is present in the result. The
    // background call and the helper are the markers of the optimize edit. The
    // two opcache markers are each UNIQUE to their edit: the mkdir comment
    // proves the cache directory is created, and the pre-flight banner (x2)
    // proves both retrieve* helpers reuse it.
    // (`'framework', 'opcache'` alone would be ambiguous: the pre-flight
    // `opcache.file_cache=...` path contains that same substring, so a file
    // with only the pre-flight edit could mis-report as fully patched while
    // the cache directory is never created.)
    $fullyPatched = str_contains($patched, 'rfaOptimizeInBackground(rfaCacheDir, rfaVersionChanged, phpOptions, phpIniSettings')
        && str_contains($patched, $helperMarker)
        && str_contains($patched, 'rfaNeedsFullOptimize')
        && str_contains($patched, '[rfa opcache] persistent opcode cache dir')
        && substr_count($patched, '[rfa opcache] reuse compiled opcode') === 2;

    return $fullyPatched ? $patched : null;
}

/**
 * Cache the per-launch pre-flight PHP boots in the compiled main bootstrap
 * (`dist/index.js`).
 *
 * Before the window opens, NativePHP shells out to `native:config`
 * (= config('nativephp')) and `native:php-ini` (= phpIni()), each a full
 * framework boot. Both outputs are static per app version — the per-launch
 * secret and API port live in the separate nativephp-internal config, read
 * directly by the PHP client, never in these outputs — so cache them in the
 * Electron store keyed by app version and reuse them on same-version launches,
 * skipping two PHP boots (~240ms warm here). Fail open: any cache miss, parse,
 * or store error falls through to the original live call, so the worst case is
 * exactly today's behaviour. Disabled under NODE_ENV=development so a developer
 * always sees fresh config.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchPreflightCache(string $content): ?string
{
    $importFind = "import electronUpdater from 'electron-updater';";
    $importMarker = 'import Store from "electron-store"; // [rfa preflight cache]';
    $importReplace = $importFind."\n".$importMarker;

    $configFind = <<<'JS'
    loadConfig() {
        return __awaiter(this, void 0, void 0, function* () {
            let config = {};
            try {
                const result = yield retrieveNativePHPConfig();
                config = JSON.parse(result.stdout);
            }
            catch (error) {
                console.error(error);
            }
            return config;
        });
    }
JS;

    $configReplace = <<<'JS'
    loadConfig() {
        return __awaiter(this, void 0, void 0, function* () {
            // [rfa preflight cache] native:config is static per app version; reuse
            // a cached copy to skip a full PHP boot. Fail open on any error.
            // accessPropertiesByDotNotation:false keeps the dotted version in the
            // key (e.g. preflight_config_1.0.0) literal instead of nesting it.
            const rfaKey = 'preflight_config_' + app.getVersion();
            if (process.env.NODE_ENV !== 'development') {
                try {
                    const rfaCached = new Store({ name: 'nativephp', accessPropertiesByDotNotation: false }).get(rfaKey);
                    if (rfaCached) {
                        return rfaCached;
                    }
                }
                catch (rfaError) { }
            }
            let config = {};
            try {
                const result = yield retrieveNativePHPConfig();
                config = JSON.parse(result.stdout);
                if (process.env.NODE_ENV !== 'development') {
                    try {
                        new Store({ name: 'nativephp', accessPropertiesByDotNotation: false }).set(rfaKey, config);
                    }
                    catch (rfaError) { }
                }
            }
            catch (error) {
                console.error(error);
            }
            return config;
        });
    }
JS;

    $phpIniFind = <<<'JS'
    loadPhpIni() {
        return __awaiter(this, void 0, void 0, function* () {
            let config = {};
            try {
                const result = yield retrievePhpIniSettings();
                config = JSON.parse(result.stdout);
            }
            catch (error) {
                console.error(error);
            }
            return config;
        });
    }
JS;

    $phpIniReplace = <<<'JS'
    loadPhpIni() {
        return __awaiter(this, void 0, void 0, function* () {
            // [rfa preflight cache] native:php-ini is static per app version; reuse
            // a cached copy to skip a full PHP boot. Fail open on any error.
            // accessPropertiesByDotNotation:false keeps the dotted version in the
            // key (e.g. preflight_phpini_1.0.0) literal instead of nesting it.
            const rfaKey = 'preflight_phpini_' + app.getVersion();
            if (process.env.NODE_ENV !== 'development') {
                try {
                    const rfaCached = new Store({ name: 'nativephp', accessPropertiesByDotNotation: false }).get(rfaKey);
                    if (rfaCached) {
                        return rfaCached;
                    }
                }
                catch (rfaError) { }
            }
            let config = {};
            try {
                const result = yield retrievePhpIniSettings();
                config = JSON.parse(result.stdout);
                if (process.env.NODE_ENV !== 'development') {
                    try {
                        new Store({ name: 'nativephp', accessPropertiesByDotNotation: false }).set(rfaKey, config);
                    }
                    catch (rfaError) { }
                }
            }
            catch (error) {
                console.error(error);
            }
            return config;
        });
    }
JS;

    $patched = $content;

    if (str_contains($patched, $importFind) && ! str_contains($patched, $importMarker)) {
        $patched = str_replace($importFind, $importReplace, $patched);
    }

    if (str_contains($patched, $configFind)) {
        $patched = str_replace($configFind, $configReplace, $patched);
    }

    if (str_contains($patched, $phpIniFind)) {
        $patched = str_replace($phpIniFind, $phpIniReplace, $patched);
    }

    // Only success when both methods and the Store import are present, so a
    // NativePHP bump that reshapes one method can't half-apply silently.
    $fullyPatched = str_contains($patched, $importMarker)
        && str_contains($patched, "'preflight_config_'")
        && str_contains($patched, "'preflight_phpini_'");

    return $fullyPatched ? $patched : null;
}

/**
 * Show an early splash window in the compiled main bootstrap (`dist/index.js`).
 *
 * NativePHP creates no window until the very end of the launch chain: Electron
 * boots, the PHP server starts, the `_native/api/booted` round-trip fires,
 * NativeAppServiceProvider::boot() calls Window::open, Electron then creates the
 * real BrowserWindow (show:false) and only `.show()`s it after its readiness
 * barrier.
 * So the user stares at a blank screen for the entire Electron-boot + PHP-boot +
 * first-render duration — the screen is dark until everything is ready.
 *
 * This opens a lightweight, frameless splash the instant `app.whenReady()`
 * resolves — before the PHP server boots — giving immediate visual feedback, and
 * hands off seamlessly: the splash closes when the opaque main window presents
 * its settled frame (the first non-splash window created, via Window::open).
 * The splash is
 * a self-contained `data:` URL so there is nothing extra to bundle, and the
 * whole thing is fail-open — any error just means no splash, i.e. today's
 * behaviour. On macOS (RFA's only desktop target) closing the splash while it is
 * the only window does not quit the app: `window-all-closed` is darwin-guarded.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchSplashWindow(string $content): ?string
{
    // 1. Pull BrowserWindow + nativeTheme into the electron import so the splash
    //    can create a window and tint it to the OS light/dark appearance.
    $importFind = 'import { app, session, powerMonitor } from "electron";';
    $importReplace = 'import { app, session, powerMonitor, BrowserWindow, nativeTheme } from "electron";';

    // 2. The splash markup, embedded as a module-level const (no asset to ship).
    //    Colors mirror RFA's own light/dark tokens (config/theme.php) and the
    //    splash themes ITSELF via `prefers-color-scheme`: an Electron data: URL
    //    follows nativeTheme (default themeSource 'system'), so on a light OS the
    //    media query stays unmatched (light palette) and on a dark OS it flips to
    //    the dark palette — matching RFA, which follows the system appearance by
    //    default. No JS in the page; the native window backgroundColor is tinted
    //    to the same appearance below so there is no wrong-color flash on open.
    $htmlAnchor = 'const { autoUpdater } = electronUpdater;';
    $splashColors = [
        '__RFA_BACKGROUND_LIGHT__' => rfaThemeHex('light', 'bg'),
        '__RFA_BACKGROUND_DARK__' => rfaThemeHex('dark', 'bg'),
        '__RFA_TEXT_LIGHT__' => rfaThemeHex('light', 'text'),
        '__RFA_TEXT_DARK__' => rfaThemeHex('dark', 'text'),
        '__RFA_TRACK_LIGHT__' => rfaThemeRgba('light', 'text', '.14'),
        '__RFA_TRACK_DARK__' => rfaThemeRgba('dark', 'text', '.18'),
        '__RFA_LINK_LIGHT__' => rfaThemeHex('light', 'link'),
        '__RFA_LINK_DARK__' => rfaThemeHex('dark', 'link'),
    ];
    $htmlConst = str_replace(
        array_keys($splashColors),
        array_values($splashColors),
        <<<'JS'
// [rfa splash] Self-contained splash markup — inline styles only, no external
// resources, so it loads instantly from a data: URL with nothing to bundle. It
// theme-matches the OS (and thus RFA's default appearance) via prefers-color-scheme.
const RFA_SPLASH_HTML = `<!doctype html><html><head><meta charset="utf-8"><style>:root{--rfa-bg:__RFA_BACKGROUND_LIGHT__;--rfa-fg:__RFA_TEXT_LIGHT__;--rfa-track:__RFA_TRACK_LIGHT__;--rfa-accent:__RFA_LINK_LIGHT__}@media (prefers-color-scheme:dark){:root{--rfa-bg:__RFA_BACKGROUND_DARK__;--rfa-fg:__RFA_TEXT_DARK__;--rfa-track:__RFA_TRACK_DARK__;--rfa-accent:__RFA_LINK_DARK__}}html,body{margin:0;height:100%;background:var(--rfa-bg);overflow:hidden}.wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--rfa-fg);-webkit-user-select:none;user-select:none}.name{font-size:22px;font-weight:600;letter-spacing:.4px;opacity:.92}.spinner{margin-top:18px;width:26px;height:26px;border:3px solid var(--rfa-track);border-top-color:var(--rfa-accent);border-radius:50%;animation:rfaspin .8s linear infinite}@keyframes rfaspin{to{transform:rotate(360deg)}}</style></head><body><div class="wrap"><div class="name">rfa</div><div class="spinner"></div></div></body></html>`;
JS
    );

    // 3. The splash lifecycle methods, injected as class members.
    $methodsAnchor = '    bootstrapApp(app) {';
    $methods = str_replace('__RFA_BACKGROUND_EXPRESSION__', rfaBackgroundExpression(), <<<'JS'
    rfaShowSplash() {
        // [rfa splash] Open a lightweight window the instant Electron is ready —
        // before the PHP server boots and the real window is created — so the
        // launch shows immediate feedback instead of a blank screen. Fail-open:
        // any error just leaves no splash (today's behaviour).
        try {
            const splash = new BrowserWindow({
                width: 480,
                height: 320,
                frame: false,
                resizable: false,
                movable: false,
                center: true,
                show: false,
                skipTaskbar: true,
                // Tint the native window fill to the OS appearance so the frame
                // shown before the data: URL paints matches the splash content
                // (and RFA's default system-following theme) — no light/dark flash.
                backgroundColor: __RFA_BACKGROUND_EXPRESSION__,
                title: 'rfa',
            });
            this.rfaSplash = splash;
            splash.once('ready-to-show', () => {
                try {
                    if (!splash.isDestroyed()) {
                        splash.show();
                    }
                }
                catch (rfaError) { }
            });
            splash.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(RFA_SPLASH_HTML));
            // Seamless handoff: the first non-splash window created is the main
            // window (opened via Window::open once PHP has booted). Close the
            // splash on RFA's explicit presentation event. A remembered maximize
            // can show the main window implicitly, but it remains transparent
            // until Electron paint and renderer readiness have both settled.
            const rfaOnCreated = (_, window) => {
                if (window === this.rfaSplash) {
                    return;
                }
                app.removeListener('browser-window-created', rfaOnCreated);
                this.rfaSplashListener = null;
                // Close on the opaque presentation handoff and on `closed` (a
                // window torn down before it presents must not strand the splash
                // until the 60s timer).
                window.once('rfa:presented', () => this.rfaCloseSplash());
                window.once('closed', () => this.rfaCloseSplash());
            };
            this.rfaSplashListener = rfaOnCreated;
            app.on('browser-window-created', rfaOnCreated);
            // Safety net: never leave a spinner stuck if no window ever opens.
            this.rfaSplashTimer = setTimeout(() => this.rfaCloseSplash(), 60000);
        }
        catch (rfaError) {
            this.rfaSplash = null;
        }
    }
    rfaCloseSplash() {
        try {
            if (this.rfaSplashTimer) {
                clearTimeout(this.rfaSplashTimer);
                this.rfaSplashTimer = null;
            }
        }
        catch (rfaError) { }
        try {
            // Drop the browser-window-created listener if the handoff never ran
            // (no main window was ever created — e.g. PHP boot failed). Otherwise
            // the closure, and the App instance it captures, leaks for the life of
            // the process.
            if (this.rfaSplashListener) {
                app.removeListener('browser-window-created', this.rfaSplashListener);
                this.rfaSplashListener = null;
            }
        }
        catch (rfaError) { }
        const splash = this.rfaSplash;
        this.rfaSplash = null;
        try {
            if (splash && !splash.isDestroyed()) {
                splash.close();
            }
        }
        catch (rfaError) { }
    }
    bootstrapApp(app) {
JS
    );

    // 4. Fire the splash the instant Electron is ready, before any PHP boot.
    $callFind = <<<'JS'
            yield app.whenReady();
            const config = yield this.loadConfig();
JS;
    $callReplace = <<<'JS'
            yield app.whenReady();
            this.rfaShowSplash(); // [rfa splash] instant feedback before PHP boots
            const config = yield this.loadConfig();
JS;

    $patched = $content;

    if (str_contains($patched, $importFind) && ! str_contains($patched, 'BrowserWindow, nativeTheme')) {
        $patched = str_replace($importFind, $importReplace, $patched);
    }

    if (str_contains($patched, $htmlAnchor) && ! str_contains($patched, 'const RFA_SPLASH_HTML')) {
        $patched = str_replace($htmlAnchor, $htmlAnchor."\n".$htmlConst, $patched);
    }

    if (str_contains($patched, $methodsAnchor) && ! str_contains($patched, 'rfaShowSplash() {')) {
        $patched = str_replace($methodsAnchor, $methods, $patched);
    }

    if (str_contains($patched, $callFind) && ! str_contains($patched, 'this.rfaShowSplash()')) {
        $patched = str_replace($callFind, $callReplace, $patched);
    }

    // -- Upgrade a file left splash-patched by the PREVIOUS RFA revision in place --
    // The earlier splash was dark-only (fixed #0d1117, no nativeTheme). A plain
    // `composer install` re-runs this hook over such a vendor copy; the guards above
    // skip when their markers exist, so the themed edits would never reach it AND
    // the success gate below (which now requires nativeTheme) would reject it and
    // fail the hook. These three finds are unique to the old shape — each is a no-op
    // on stock (handled above) and on an already-themed file — so re-patching
    // converges to a result byte-identical to a fresh stock patch.

    // a) Add nativeTheme to an old BrowserWindow-only import.
    $oldSplashImport = 'import { app, session, powerMonitor, BrowserWindow } from "electron";';
    if (str_contains($patched, $oldSplashImport) && ! str_contains($patched, 'BrowserWindow, nativeTheme')) {
        $patched = str_replace($oldSplashImport, $importReplace, $patched);
    }

    // b) Swap the dark-only splash markup for the OS-following themed markup.
    $oldSplashHtmlBlock = <<<'JS'
// [rfa splash] Self-contained splash markup — inline styles only, no external
// resources, so it loads instantly from a data: URL with nothing to bundle.
const RFA_SPLASH_HTML = `<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;height:100%;background:#0d1117;overflow:hidden}.wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#e6edf3;-webkit-user-select:none;user-select:none}.name{font-size:22px;font-weight:600;letter-spacing:.4px;opacity:.92}.spinner{margin-top:18px;width:26px;height:26px;border:3px solid rgba(230,237,243,.18);border-top-color:#58a6ff;border-radius:50%;animation:rfaspin .8s linear infinite}@keyframes rfaspin{to{transform:rotate(360deg)}}</style></head><body><div class="wrap"><div class="name">rfa</div><div class="spinner"></div></div></body></html>`;
JS;
    if (str_contains($patched, $oldSplashHtmlBlock)) {
        $patched = str_replace($oldSplashHtmlBlock, $htmlConst, $patched);
    }

    // c) Tint the native window fill to the OS appearance instead of a fixed dark.
    //    The replacement must stay byte-identical to the same lines baked into
    //    $methods above (so an upgraded file equals a fresh stock patch).
    $oldSplashBgBlock = <<<'JS'
                show: false,
                skipTaskbar: true,
                backgroundColor: '#0d1117',
                title: 'rfa',
JS;
    $newSplashBgBlock = str_replace('__RFA_BACKGROUND_EXPRESSION__', rfaBackgroundExpression(), <<<'JS'
                show: false,
                skipTaskbar: true,
                // Tint the native window fill to the OS appearance so the frame
                // shown before the data: URL paints matches the splash content
                // (and RFA's default system-following theme) — no light/dark flash.
                backgroundColor: __RFA_BACKGROUND_EXPRESSION__,
                title: 'rfa',
JS
    );
    if (str_contains($patched, $oldSplashBgBlock)) {
        $patched = str_replace($oldSplashBgBlock, $newSplashBgBlock, $patched);
    }

    $previousPresentationListener = <<<'JS'
                // Close on `show` (painted — the seamless handoff) and on `closed`
                // (a window torn down before it ever shows — e.g. a failed load —
                // must not strand the splash until the 60s timer).
                window.once('show', () => this.rfaCloseSplash());
JS;
    $presentationListener = <<<'JS'
                // Close on the opaque presentation handoff and on `closed` (a
                // window torn down before it presents must not strand the splash
                // until the 60s timer).
                window.once('rfa:presented', () => this.rfaCloseSplash());
JS;

    if (str_contains($patched, $previousPresentationListener)) {
        $patched = str_replace($previousPresentationListener, $presentationListener, $patched);
    }

    // Only success when every edit is present, so a NativePHP bump that reshapes
    // one anchor can't half-apply (e.g. a splash that is created but never shown,
    // or themed markup without the nativeTheme import that tints the window).
    $fullyPatched = str_contains($patched, 'BrowserWindow, nativeTheme')
        && str_contains($patched, $htmlConst)
        && str_contains($patched, 'nativeTheme.shouldUseDarkColors')
        && str_contains($patched, 'rfaShowSplash() {')
        && str_contains($patched, "window.once('rfa:presented'")
        && str_contains($patched, 'this.rfaShowSplash()');

    return $fullyPatched ? $patched : null;
}

/**
 * Resolve RFA's persisted appearance before either native window is created.
 *
 * Flux stores the selected appearance in renderer localStorage. The theme
 * switcher mirrors that value to a cookie so Electron can read it before the
 * renderer exists. Setting nativeTheme first gives the splash, main window
 * fill, media queries, and Flux page the same light, dark, or system choice.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchResolvedAppearance(string $content): ?string
{
    $methodsAnchor = '    rfaShowSplash() {';
    $methodsReplace = str_replace('__RFA_BACKGROUND_EXPRESSION__', rfaBackgroundExpression(), <<<'JS'
    rfaResolveAppearance() {
        return __awaiter(this, void 0, void 0, function* () {
            try {
                const rfaAppearanceCookies = yield session.defaultSession.cookies.get({
                    url: 'http://127.0.0.1',
                    name: 'rfa_appearance',
                });
                let rfaAppearance = 'system';
                if (rfaAppearanceCookies.length > 0) {
                    rfaAppearance = rfaAppearanceCookies[0].value;
                }
                else {
                    const rfaLegacyThemeCookies = yield session.defaultSession.cookies.get({
                        url: 'http://127.0.0.1',
                        name: 'rfa_theme',
                    });
                    if (rfaLegacyThemeCookies.length > 0) {
                        rfaAppearance = rfaLegacyThemeCookies[0].value;
                    }
                }
                nativeTheme.themeSource = ['light', 'dark', 'system'].includes(rfaAppearance)
                    ? rfaAppearance
                    : 'system';
            }
            catch (rfaError) {
                nativeTheme.themeSource = 'system';
            }
        });
    }
    rfaBackgroundColor() {
        return __RFA_BACKGROUND_EXPRESSION__;
    }
    rfaShowSplash() {
JS
    );

    $previousCookieLookup = <<<'JS'
                const rfaCookies = yield session.defaultSession.cookies.get({
                    url: 'http://127.0.0.1',
                    name: 'rfa_appearance',
                });
                const rfaAppearance = rfaCookies.length > 0 ? rfaCookies[0].value : 'system';
JS;
    $cookieLookup = <<<'JS'
                const rfaAppearanceCookies = yield session.defaultSession.cookies.get({
                    url: 'http://127.0.0.1',
                    name: 'rfa_appearance',
                });
                let rfaAppearance = 'system';
                if (rfaAppearanceCookies.length > 0) {
                    rfaAppearance = rfaAppearanceCookies[0].value;
                }
                else {
                    const rfaLegacyThemeCookies = yield session.defaultSession.cookies.get({
                        url: 'http://127.0.0.1',
                        name: 'rfa_theme',
                    });
                    if (rfaLegacyThemeCookies.length > 0) {
                        rfaAppearance = rfaLegacyThemeCookies[0].value;
                    }
                }
JS;

    $backgroundFind = 'backgroundColor: '.rfaBackgroundExpression().',';
    $backgroundReplace = 'backgroundColor: this.rfaBackgroundColor(),';

    $callFind = <<<'JS'
            yield app.whenReady();
            this.rfaShowSplash(); // [rfa splash] instant feedback before PHP boots
JS;
    $callReplace = <<<'JS'
            yield app.whenReady();
            yield this.rfaResolveAppearance(); // [rfa appearance] resolve before either window exists
            this.rfaShowSplash(); // [rfa splash] instant feedback before PHP boots
JS;

    $patched = $content;

    if (str_contains($patched, $methodsAnchor) && ! str_contains($patched, 'rfaResolveAppearance() {')) {
        $patched = str_replace($methodsAnchor, $methodsReplace, $patched);
    }

    if (str_contains($patched, $previousCookieLookup)) {
        $patched = str_replace($previousCookieLookup, $cookieLookup, $patched);
    }

    if (str_contains($patched, $backgroundFind)) {
        $patched = str_replace($backgroundFind, $backgroundReplace, $patched);
    }

    if (str_contains($patched, $callFind)) {
        $patched = str_replace($callFind, $callReplace, $patched);
    }

    $fullyPatched = str_contains($patched, 'rfaResolveAppearance() {')
        && str_contains($patched, "name: 'rfa_appearance'")
        && str_contains($patched, "name: 'rfa_theme'")
        && str_contains($patched, "nativeTheme.themeSource = ['light', 'dark', 'system'].includes(rfaAppearance)")
        && str_contains($patched, 'rfaBackgroundColor() {')
        && str_contains($patched, $backgroundReplace)
        && str_contains($patched, 'yield this.rfaResolveAppearance(); // [rfa appearance]');

    return $fullyPatched ? $patched : null;
}

/**
 * Extract the bundled PHP binary before packaging continues.
 *
 * NativePHP streams its one-file archive through yauzl. On Node 26 that stream
 * can stop before EOF while the process still exits successfully, which lets
 * Electron Builder package a truncated executable. Read and inflate the small
 * build archive synchronously, validate its shape and size, and only then make
 * the binary executable.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchPhpExtraction(string $content): ?string
{
    $importFind = 'import unzip from "yauzl";';
    $importReplace = 'import { inflateRawSync } from "zlib"; // [rfa php extraction]';
    $blockFind = <<<'JS'
if (platform.phpBinary) {
    try {
        console.log('Unzipping PHP binary from ' + binarySrcDir + ' to ' + binaryDestDir);
        removeSync(binaryDestDir);

        ensureDirSync(binaryDestDir);

        // Unzip the files
        unzip.open(binarySrcDir, {lazyEntries: true}, function (err, zipfile) {
            if (err) throw err;
            zipfile.readEntry();
            zipfile.on("entry", function (entry) {
                zipfile.openReadStream(entry, function (err, readStream) {
                    if (err) throw err;

                    const binaryPath = join(binaryDestDir, platform.phpBinary);
                    const writeStream = fs.createWriteStream(binaryPath);

                    readStream.pipe(writeStream);

                    writeStream.on("close", function() {
                        console.log('Copied PHP binary to ', binaryPath);

                        // Add execute permissions
                        fs.chmod(binaryPath, 0o755, (err) => {
                            if (err) {
                                console.log(`Error setting permissions: ${err}`);
                            }
                        });

                        zipfile.readEntry();
                    });
                });
            });
        });
    } catch (e) {
        console.error('Error copying PHP binary', e);
    }
}
JS;
    $replacement = <<<'JS'
if (platform.phpBinary) {
    try {
        console.log('Unzipping PHP binary from ' + binarySrcDir + ' to ' + binaryDestDir);
        removeSync(binaryDestDir);
        ensureDirSync(binaryDestDir);

        // [rfa php archive validation] NativePHP ships one local file header.
        const archive = fs.readFileSync(binarySrcDir);
        if (archive.readUInt32LE(0) !== 0x04034b50) {
            throw new Error('Invalid PHP zip local header');
        }

        const flags = archive.readUInt16LE(6);
        const compressionMethod = archive.readUInt16LE(8);
        const compressedSize = archive.readUInt32LE(18);
        const uncompressedSize = archive.readUInt32LE(22);
        const fileNameLength = archive.readUInt16LE(26);
        const extraFieldLength = archive.readUInt16LE(28);
        const dataOffset = 30 + fileNameLength + extraFieldLength;
        const fileName = archive.toString('utf8', 30, 30 + fileNameLength);

        if ((flags & 0x09) !== 0 || fileName !== platform.phpBinary) {
            throw new Error('Unsupported PHP zip shape');
        }

        const compressed = archive.subarray(dataOffset, dataOffset + compressedSize);
        const binary = compressionMethod === 0
            ? Buffer.from(compressed)
            : compressionMethod === 8
                ? inflateRawSync(compressed)
                : null;

        if (binary === null || binary.length !== uncompressedSize) {
            throw new Error('Incomplete PHP binary extraction');
        }

        const binaryPath = join(binaryDestDir, platform.phpBinary);
        fs.writeFileSync(binaryPath, binary, {mode: 0o755});
        fs.chmodSync(binaryPath, 0o755);
        console.log('Copied PHP binary to ', binaryPath);
    } catch (e) {
        console.error('Error copying PHP binary', e);
        process.exitCode = 1;
    }
}
JS;
    $destinationPreparation = <<<'JS'
        console.log('Unzipping PHP binary from ' + binarySrcDir + ' to ' + binaryDestDir);
        removeSync(binaryDestDir);
        ensureDirSync(binaryDestDir);

JS;
    $previousReplacement = str_replace($destinationPreparation, '', $replacement);
    $isStock = str_contains($content, $importFind)
        && str_contains($content, $blockFind);
    $isCurrent = str_contains($content, $importReplace)
        && str_contains($content, $replacement);
    $isPrevious = str_contains($content, $importReplace)
        && str_contains($content, $previousReplacement);

    if (! $isStock && ! $isCurrent && ! $isPrevious) {
        return null;
    }

    if ($isStock) {
        $content = str_replace($blockFind, $replacement, $content);
        $content = str_replace($importFind, $importReplace, $content);
    }

    if ($isPrevious) {
        $content = str_replace($previousReplacement, $replacement, $content);
    }

    $fullyPatched = str_contains($content, $importReplace)
        && str_contains($content, $replacement)
        && ! str_contains($content, $importFind)
        && ! str_contains($content, $blockFind)
        && ! str_contains($content, $previousReplacement);

    return $fullyPatched ? $content : null;
}

/**
 * Wait for PHP extraction before Electron Builder copies the app resources.
 *
 * NativePHP's beforePack hook launches its extractor as an unawaited child
 * process. Electron Builder can begin its copy while the binary still has the
 * write stream's initial 0644 mode. A synchronous child process makes the hook
 * a real packaging barrier and also propagates extractor failures.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchPhpBuildWait(string $content): ?string
{
    $importFind = "import { exec } from 'child_process';";
    $importReplace = "import { execFileSync } from 'child_process'; // [rfa php build wait]";
    $permissionImport = "import { chmodSync } from 'fs'; // [rfa php build permission]";
    $pathImport = "import { join } from 'path'; // [rfa php build path]";
    $callFind = '        exec(`node php.js --${targetOs} --${arch}`);';
    $callReplace = "        execFileSync(process.execPath, ['php.js', `--\${targetOs}`, `--\${arch}`], { stdio: 'inherit' });";
    $previousCallReplace = <<<'JS'
        execFileSync(process.execPath, ['php.js', `--${targetOs}`, `--${arch}`], { stdio: 'inherit' });
        if (targetOs !== 'win') {
            chmodSync(join(process.env.NATIVEPHP_BUILD_PATH, 'php', 'php'), 0o755);
        }
JS;
    $isStock = str_contains($content, $importFind)
        && str_contains($content, $callFind);
    $isCurrent = str_contains($content, $importReplace)
        && str_contains($content, $callReplace)
        && ! str_contains($content, $permissionImport)
        && ! str_contains($content, $pathImport)
        && ! str_contains($content, $previousCallReplace);
    $isPrevious = str_contains($content, $importReplace)
        && str_contains($content, $permissionImport)
        && str_contains($content, $previousCallReplace);

    if (! $isStock && ! $isCurrent && ! $isPrevious) {
        return null;
    }

    if ($isStock) {
        $content = str_replace($importFind, $importReplace, $content);
        $content = str_replace($callFind, $callReplace, $content);
    }

    if ($isPrevious) {
        $content = str_replace($permissionImport."\n", '', $content);
        $content = str_replace($pathImport."\n", '', $content);
        $content = str_replace($previousCallReplace, $callReplace, $content);
    }

    $fullyPatched = str_contains($content, '[rfa php build wait]')
        && str_contains($content, $callReplace)
        && ! str_contains($content, $callFind)
        && ! str_contains($content, $permissionImport)
        && ! str_contains($content, $pathImport)
        && ! str_contains($content, $previousCallReplace);

    return $fullyPatched ? $content : null;
}

/**
 * Start the PHP server before Electron is ready and warm its opcache.
 *
 * NativePHP waits for `app.whenReady()`, resolves the appearance, opens the
 * splash, and only then spawns the PHP server, whose first request (the
 * `booted` handshake) then compiles the whole framework from scratch. None
 * of the PHP set-up depends on Electron being ready, so the server is spawned
 * as soon as the main bundle runs and asked to pre-compile the scripts the
 * previous launches needed (`/_rfa/warm`, see OpcacheWarmService) while
 * Electron finishes starting. The `booted` handshake and the first page
 * request then run against a warm opcache. Fail open: a warm-up error or
 * timeout only costs the cold first request this patch exists to avoid.
 *
 * Applied after the splash and appearance patches, which own the lines it
 * moves around `app.whenReady()`.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchEarlyPhpBoot(string $content): ?string
{
    $importFind = 'import Store from "electron-store"; // [rfa preflight cache]';
    $importMarker = 'import axios from "axios"; // [rfa early php]';
    $importReplace = $importFind."\n".$importMarker;

    $bootFind = <<<'JS'
            yield app.whenReady();
            yield this.rfaResolveAppearance(); // [rfa appearance] resolve before either window exists
            this.rfaShowSplash(); // [rfa splash] instant feedback before PHP boots
            const config = yield this.loadConfig();
            this.setDockIcon();
            this.setAppUserModelId(config);
            this.setDeepLinkHandler(config);
            this.startAutoUpdater(config);
            yield this.startElectronApi();
            state.phpIni = yield this.loadPhpIni();
            yield this.startPhpApp();
            this.startScheduler();
JS;

    $bootReplace = <<<'JS'
            // [rfa early php] Nothing the PHP server needs waits on Electron's
            // readiness, so it is spawned first and pre-compiles its opcache
            // while Electron finishes starting and the splash appears. The
            // `booted` handshake below then reaches a warm server. The config
            // is only needed after readiness, so its own PHP boot (on a
            // pre-flight cache miss) overlaps with the server spawn.
            const rfaConfig = this.loadConfig();
            yield this.startElectronApi();
            state.phpIni = yield this.loadPhpIni();
            const rfaPhpBoot = this.startPhpApp().then(() => this.rfaWarmPhp()).then(() => null, (rfaError) => rfaError);
            yield app.whenReady();
            yield this.rfaResolveAppearance(); // [rfa appearance] resolve before either window exists
            this.rfaShowSplash(); // [rfa splash] instant feedback before PHP boots
            const config = yield rfaConfig;
            this.setDockIcon();
            this.setAppUserModelId(config);
            this.setDeepLinkHandler(config);
            this.startAutoUpdater(config);
            const rfaPhpFailure = yield rfaPhpBoot;
            if (rfaPhpFailure) {
                throw rfaPhpFailure;
            }
            this.startScheduler();
JS;

    $methodsAnchor = <<<'JS'
    loadPhpIni() {
JS;

    $methodsReplace = <<<'JS'
    rfaWarmPhp() {
        // [rfa early php] Ask the server to compile the scripts earlier page
        // loads needed into opcache shared memory. Best effort: an error or a
        // slow answer never holds the window back for long. Resolves to
        // nothing either way: the boot chain treats any value as a failure.
        return axios.get(`http://127.0.0.1:${state.phpPort}/_rfa/warm`, {
            headers: { 'X-NativePHP-Secret': state.randomSecret },
            proxy: false,
            timeout: 4000,
        }).then(() => undefined, () => undefined);
    }
    loadPhpIni() {
JS;

    if (str_contains($content, $importFind) && ! str_contains($content, $importMarker)) {
        $content = str_replace($importFind, $importReplace, $content);
    }

    if (str_contains($content, $bootFind)) {
        $content = str_replace($bootFind, $bootReplace, $content);
    }

    if (str_contains($content, $methodsAnchor) && ! str_contains($content, 'rfaWarmPhp() {')) {
        $content = str_replace($methodsAnchor, $methodsReplace, $content);
    }

    $fullyPatched = str_contains($content, $importMarker)
        && str_contains($content, $bootReplace)
        && str_contains($content, $methodsReplace)
        && ! str_contains($content, $bootFind);

    return $fullyPatched ? $content : null;
}

/**
 * Make the PHP secret cookie wait for Electron readiness.
 *
 * `startPhpApp()` stores the shared secret in the default session once the
 * server is listening. With the server spawned before `app.whenReady()`
 * (see rfaPatchEarlyPhpBoot), a fast server wins that race and
 * `session.defaultSession` throws "Session can only be received when app
 * is ready", which aborts the whole bootstrap behind the splash.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchCookieAfterReady(string $content): ?string
{
    $importFind = "import { session } from 'electron';";
    $importReplace = "import { app, session } from 'electron'; // [rfa cookie after ready]";

    $find = <<<'JS'
        yield session.defaultSession.cookies.set(cookie);
JS;

    $replace = <<<'JS'
        yield app.whenReady(); // [rfa cookie after ready] the server may be up first
        yield session.defaultSession.cookies.set(cookie);
JS;

    if (str_contains($content, $importFind)) {
        $content = str_replace($importFind, $importReplace, $content);
    }

    if (str_contains($content, $find) && ! str_contains($content, $replace)) {
        $content = str_replace($find, $replace, $content);
    }

    $fullyPatched = str_contains($content, $importReplace) && str_contains($content, $replace);

    return $fullyPatched ? $content : null;
}

/**
 * Run PHP's built-in server with forked workers.
 *
 * The stock server answers one request at a time. During a launch the first
 * page load, the Livewire lazy bundles, the change poll, and the native event
 * callbacks all queue behind each other; while reviewing, a poll can stall a
 * click. Workers let them overlap. opcache shared memory is mapped before the
 * fork, so the opcode warmed by one worker serves every worker.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchServerWorkers(string $content): ?string
{
    $find = <<<'JS'
        const phpServer = callPhp(['-S', `127.0.0.1:${phpPort}`, serverPath], {
            cwd: cwd,
            env
        }, phpIniSettings);
JS;

    $replace = <<<'JS'
        // [rfa php workers] see scripts/patch-nativephp.php
        const phpServer = callPhp(['-S', `127.0.0.1:${phpPort}`, serverPath], {
            cwd: cwd,
            env: Object.assign({}, env, { PHP_CLI_SERVER_WORKERS: '4' })
        }, phpIniSettings);
JS;

    if (str_contains($content, $find)) {
        $content = str_replace($find, $replace, $content);
    }

    return str_contains($content, $replace) ? $content : null;
}

/**
 * Record the main-process launch timeline.
 *
 * Every phase of the boot gets a mark in milliseconds since process creation,
 * and the set is appended as one JSON line to `storage/logs/rfa-launch.jsonl`
 * under userData once the main window has presented (or after a fallback
 * delay). The PHP side and the renderer stamp the diagnostics log with the
 * same epoch base, so `rfa:launch-report` lays the three out on one timeline.
 *
 * The marks wrap the bootstrap methods on the prototype instead of editing
 * the bootstrap sequence, which the pre-flight, splash, appearance, and early
 * PHP boot patches own and verify by exact text.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchLaunchTimeline(string $content): ?string
{
    $importFind = 'import { resolve } from "path";';
    $importReplace = <<<'JS'
import { resolve, join } from "path"; // [rfa launch timeline]
import { appendFileSync, mkdirSync, renameSync, statSync } from "fs"; // [rfa launch timeline]
JS;

    $helperFind = 'const { autoUpdater } = electronUpdater;';
    $helperMarker = 'function rfaLaunchMark(name) {';
    $helperReplace = <<<'JS'
const { autoUpdater } = electronUpdater;
// [rfa launch timeline] Marks are milliseconds since process creation. The
// set is flushed once, shortly after the main window presents, so a launch
// that never presents still leaves a line via the fallback timer.
const rfaLaunch = {
    t0: typeof process.getCreationTime === 'function' && process.getCreationTime()
        ? process.getCreationTime()
        : Date.now() - Math.round(process.uptime() * 1000),
    marks: [],
    flushed: false,
    flushTimer: null,
    deadline: Infinity,
};
function rfaLaunchMark(name) {
    if (rfaLaunch.flushed) {
        return;
    }
    rfaLaunch.marks.push({ name, at: Date.now() });
    if (name === 'window.presented' && rfaLaunch.flushTimer !== null) {
        clearTimeout(rfaLaunch.flushTimer);
        rfaLaunch.flushTimer = setTimeout(rfaLaunchFlush, 1500);
    }
}
function rfaLaunchMarked(name) {
    return rfaLaunch.marks.some((mark) => mark.name === name);
}
function rfaLaunchFlush() {
    if (rfaLaunch.flushed) {
        return;
    }
    // A background cache rebuild outlives the presented window; hold the
    // line for its finish mark, but never past the deadline.
    if (Date.now() < rfaLaunch.deadline && rfaLaunchMarked('php.optimize.started') && !rfaLaunchMarked('php.optimize.finished')) {
        rfaLaunch.flushTimer = setTimeout(rfaLaunchFlush, 500);
        return;
    }
    rfaLaunch.flushed = true;
    try {
        if (rfaLaunch.flushTimer !== null) {
            clearTimeout(rfaLaunch.flushTimer);
            rfaLaunch.flushTimer = null;
        }
        const marks = {};
        rfaLaunch.marks.forEach((mark) => {
            if (!(mark.name in marks)) {
                marks[mark.name] = mark.at - rfaLaunch.t0;
            }
        });
        const line = JSON.stringify({
            ts: new Date().toISOString(),
            event: 'launch.timeline',
            pid: process.pid,
            version: app.getVersion(),
            packaged: app.isPackaged,
            t0_ms: rfaLaunch.t0,
            marks,
        }) + '\n';
        const dir = join(app.getPath('userData'), 'storage', 'logs');
        const file = join(dir, 'rfa-launch.jsonl');
        mkdirSync(dir, { recursive: true });
        try {
            if (statSync(file).size > 1024 * 1024) {
                renameSync(file, file + '.1');
            }
        }
        catch (rfaError) { }
        appendFileSync(file, line);
    }
    catch (rfaError) { }
}
globalThis.__rfaLaunchMark = rfaLaunchMark;
app.whenReady().then(() => rfaLaunchMark('app.ready'));
JS;

    $bootedFind = <<<'JS'
            yield notifyLaravel("booted");
JS;
    $bootedReplace = <<<'JS'
            rfaLaunchMark('booted.sent'); // [rfa launch timeline]
            yield notifyLaravel("booted");
            rfaLaunchMark('booted.acked');
JS;

    $exportFind = 'export default new NativePHP();';
    $exportReplace = <<<'JS'
// [rfa launch timeline] Each bootstrap step is marked when it settles. Wrapping
// the prototype leaves the bootstrap sequence itself untouched.
[
    ['bootstrap', 'bootstrap', 'before'],
    ['startElectronApi', 'api.started', 'after'],
    ['loadPhpIni', 'phpini.loaded', 'after'],
    ['loadConfig', 'config.loaded', 'after'],
    ['startPhpApp', 'php.started', 'after'],
    ['rfaWarmPhp', 'php.warmed', 'after'],
    ['rfaResolveAppearance', 'appearance.resolved', 'after'],
    ['rfaShowSplash', 'splash.requested', 'after'],
    ['startAutoUpdater', 'updater.started', 'after'],
].forEach(([method, mark, when]) => {
    const original = NativePHP.prototype[method];
    if (typeof original !== 'function') {
        return;
    }
    NativePHP.prototype[method] = function (...args) {
        if (when === 'before') {
            rfaLaunchMark(mark);
            rfaLaunch.deadline = Date.now() + 60000;
            rfaLaunch.flushTimer = setTimeout(rfaLaunchFlush, 60000);
        }
        const result = original.apply(this, args);
        const settle = (value) => {
            rfaLaunchMark(mark);
            if (method === 'rfaShowSplash' && this.rfaSplash) {
                this.rfaSplash.once('show', () => rfaLaunchMark('splash.shown'));
                this.rfaSplash.once('closed', () => rfaLaunchMark('splash.closed'));
            }
            return value;
        };
        if (when === 'before') {
            return result;
        }
        return result && typeof result.then === 'function' ? result.then(settle) : settle(result);
    };
});
export default new NativePHP();
JS;

    if (str_contains($content, $importFind) && ! str_contains($content, $importReplace)) {
        $content = str_replace($importFind, $importReplace, $content);
    }

    if (str_contains($content, $helperFind) && ! str_contains($content, $helperMarker)) {
        $content = str_replace($helperFind, $helperReplace, $content);
    }

    if (str_contains($content, $bootedFind) && ! str_contains($content, $bootedReplace)) {
        $content = str_replace($bootedFind, $bootedReplace, $content);
    }

    if (str_contains($content, $exportFind) && ! str_contains($content, $exportReplace)) {
        $content = str_replace($exportFind, $exportReplace, $content);
    }

    $fullyPatched = str_contains($content, $importReplace)
        && str_contains($content, $helperReplace)
        && str_contains($content, $bootedReplace)
        && str_contains($content, $exportReplace);

    return $fullyPatched ? $content : null;
}

/**
 * Stamp the PHP server's start-up phases on the launch timeline.
 *
 * `index.js` owns the mark collector, so this file calls the global it
 * publishes. The optional chaining keeps the server usable when the
 * collector is absent (a test harness importing php.js alone).
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchLaunchTimelineServer(string $content): ?string
{
    $edits = [
        [
            "            let result = callPhpSync(['artisan', 'migrate', '--force'], phpOptions, phpIniSettings);\n",
            "            globalThis.__rfaLaunchMark?.('php.migrate.started'); // [rfa launch timeline]\n            let result = callPhpSync(['artisan', 'migrate', '--force'], phpOptions, phpIniSettings);\n            globalThis.__rfaLaunchMark?.('php.migrate.finished');\n",
        ],
        [
            "        const phpPort = yield getPhpPort();\n",
            "        globalThis.__rfaLaunchMark?.('php.spawning'); // [rfa launch timeline]\n        const phpPort = yield getPhpPort();\n        globalThis.__rfaLaunchMark?.('php.port');\n",
        ],
        [
            "        const portRegex = /Development Server \\(.*:([0-9]+)\\) started/gm;\n",
            "        globalThis.__rfaLaunchMark?.('php.spawned'); // [rfa launch timeline]\n        const portRegex = /Development Server \\(.*:([0-9]+)\\) started/gm;\n",
        ],
        [
            "                console.log(\"PHP Server started on port: \", port);\n",
            "                globalThis.__rfaLaunchMark?.('php.listening'); // [rfa launch timeline]\n                console.log(\"PHP Server started on port: \", port);\n",
        ],
    ];

    foreach ($edits as [$find, $replace]) {
        if (str_contains($content, $find) && ! str_contains($content, $replace)) {
            $content = str_replace($find, $replace, $content);
        }
    }

    $fullyPatched = true;

    foreach ($edits as [, $replace]) {
        $fullyPatched = $fullyPatched && str_contains($content, $replace);
    }

    return $fullyPatched ? $content : null;
}

/**
 * Stamp the main window's life cycle on the launch timeline.
 *
 * Marks the open request, window creation, DOM readiness, load completion,
 * Electron's first frame, the renderer-ready signal, and presentation. Only
 * the main window is stamped: it is the one the launch waits for. The marks
 * listen to window events next to the URL load, so the readiness block the
 * renderer-ready patch owns stays untouched.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchLaunchTimelineWindow(string $content): ?string
{
    $openFind = <<<'JS'
router.post('/open', (req, res) => {
JS;
    $openReplace = <<<'JS'
router.post('/open', (req, res) => {
    // [rfa launch timeline] index.js publishes the collector; only the main
    // window is on the launch path.
    const rfaMark = (name) => {
        if (req.body.id === 'main') {
            globalThis.__rfaLaunchMark?.(name);
        }
    };
    rfaMark('window.open');
JS;

    $loadFind = <<<'JS'
    window.loadURL(url);
    window.webContents.on('dom-ready', () => {
        window.webContents.setZoomFactor(parseFloat(zoomFactor));
    });
JS;
    $loadReplace = <<<'JS'
    rfaMark('window.created'); // [rfa launch timeline]
    window.once('ready-to-show', () => rfaMark('window.ready-to-show'));
    window.once('rfa:presented', () => rfaMark('window.presented'));
    window.webContents.once('did-finish-load', () => rfaMark('window.loaded'));
    window.webContents.on('ipc-message', (_, channel) => {
        if (channel === 'rfa:renderer-ready') {
            rfaMark('window.renderer-ready');
        }
    });
    window.loadURL(url);
    window.webContents.on('dom-ready', () => {
        rfaMark('window.dom-ready');
        window.webContents.setZoomFactor(parseFloat(zoomFactor));
    });
JS;

    if (str_contains($content, $openFind) && ! str_contains($content, $openReplace)) {
        $content = str_replace($openFind, $openReplace, $content);
    }

    if (str_contains($content, $loadFind) && ! str_contains($content, $loadReplace)) {
        $content = str_replace($loadFind, $loadReplace, $content);
    }

    $fullyPatched = str_contains($content, $openReplace) && str_contains($content, $loadReplace);

    return $fullyPatched ? $content : null;
}

/**
 * The patch set: what has to be true of the vendored Electron plugin.
 *
 * Order matters within a file. The pre-flight cache, splash window, resolved
 * appearance, early PHP boot, and launch timeline rewrite `dist/index.js`,
 * and each is applied to the result of the one before it.
 *
 * @return list<array{name: string, file: string, apply: callable(string): ?string, summary: string}>
 */
function rfaNativePhpPatchSet(): array
{
    return [
        [
            'name' => 'preload-file-bridge',
            'file' => 'preload/index.mjs',
            'apply' => rfaPatchPreloadFileBridge(...),
            'summary' => 'preload exposes webUtils.getPathForFile for drag-and-drop',
        ],
        [
            'name' => 'preload-renderer-ready',
            'file' => 'preload/index.mjs',
            'apply' => rfaPatchPreloadRendererReady(...),
            'summary' => 'preload exposes a fixed renderer-ready IPC signal',
        ],
        [
            'name' => 'window-theme',
            'file' => 'server/api/window.js',
            'apply' => rfaPatchWindowTheme(...),
            'summary' => 'window fills use the resolved RFA appearance',
        ],
        [
            'name' => 'renderer-ready-window',
            'file' => 'server/api/window.js',
            'apply' => rfaPatchRendererReadyWindow(...),
            'summary' => 'windows wait for first paint and main waits for renderer stability',
        ],
        [
            'name' => 'launch-timeline-window',
            'file' => 'server/api/window.js',
            'apply' => rfaPatchLaunchTimelineWindow(...),
            'summary' => 'main window life cycle is stamped on the launch timeline',
        ],
        [
            'name' => 'server-optimize',
            'file' => 'server/php.js',
            'apply' => rfaPatchServerOptimize(...),
            'summary' => 'optimize once per version + opcache-warmed pre-flight boots',
        ],
        [
            'name' => 'server-workers',
            'file' => 'server/php.js',
            'apply' => rfaPatchServerWorkers(...),
            'summary' => 'PHP built-in server runs forked workers',
        ],
        [
            'name' => 'launch-timeline-server',
            'file' => 'server/php.js',
            'apply' => rfaPatchLaunchTimelineServer(...),
            'summary' => 'PHP server start-up phases are stamped on the launch timeline',
        ],
        [
            'name' => 'cookie-after-ready',
            'file' => 'server/utils.js',
            'apply' => rfaPatchCookieAfterReady(...),
            'summary' => 'PHP secret cookie waits for Electron readiness',
        ],
        [
            'name' => 'preflight-cache',
            'file' => 'index.js',
            'apply' => rfaPatchPreflightCache(...),
            'summary' => 'native:config / native:php-ini reused per app version',
        ],
        [
            'name' => 'splash-window',
            'file' => 'index.js',
            'apply' => rfaPatchSplashWindow(...),
            'summary' => 'splash window opens before the PHP server boots',
        ],
        [
            'name' => 'resolved-appearance',
            'file' => 'index.js',
            'apply' => rfaPatchResolvedAppearance(...),
            'summary' => 'Electron resolves the persisted appearance before creating windows',
        ],
        [
            'name' => 'early-php-boot',
            'file' => 'index.js',
            'apply' => rfaPatchEarlyPhpBoot(...),
            'summary' => 'PHP server starts before Electron is ready and warms its opcache',
        ],
        [
            'name' => 'launch-timeline',
            'file' => 'index.js',
            'apply' => rfaPatchLaunchTimeline(...),
            'summary' => 'main-process launch phases are written to storage/logs/rfa-launch.jsonl',
        ],
        [
            'name' => 'php-extraction',
            'file' => '../../php.js',
            'apply' => rfaPatchPhpExtraction(...),
            'summary' => 'PHP archive is fully validated and extracted before packaging',
        ],
        [
            'name' => 'php-build-wait',
            'file' => '../../electron-builder.mjs',
            'apply' => rfaPatchPhpBuildWait(...),
            'summary' => 'Electron Builder waits for PHP extraction before copying resources',
        ],
    ];
}

/**
 * Every edit the set makes carries a `[rfa <name>]` comment, so a target
 * holding this text was written by some revision of this script.
 */
const RFA_PATCH_MARKER = '[rfa ';

/**
 * Apply the whole patch set under `$distRoot`, or none of it.
 *
 * Patches are always applied to the stock file, never to the output of an
 * earlier revision of this script. The first run over a vendor tree copies
 * each target into `$stockRoot` before rewriting it; every later run patches
 * that copy and writes the result over whatever the target holds, so editing
 * a patch needs no upgrade path from its previous output. A target that
 * carries rfa edits but has no stock copy comes from a script revision that
 * kept none, and is refused with a reinstall hint rather than guessed at.
 *
 * Three phases, in order:
 *
 *  1. **Preflight.** Read each target once, pick its stock text, and run the
 *     edits in memory. A file that is missing entirely is reported as absent
 *     and skipped — the release build re-runs this hook over a pruned
 *     `--no-dev` copy where the plugin dist legitimately isn't there. A file
 *     that is present but whose expected shape is gone blocks the run.
 *  2. **Abort on any block.** Nothing has been written yet, so there is nothing
 *     to undo.
 *  3. **Write.** A missing or outdated stock copy is stored first. Each
 *     changed target then goes to a sibling temporary file that is renamed
 *     into place, so a reader never sees a half-written file. If a later
 *     write fails, the targets already renamed are restored from the
 *     originals held in memory.
 *
 * @return array{applied: list<string>, unchanged: list<string>, blocked: list<string>, stale: list<string>, absent: list<string>, written: list<string>, error: ?string, rolledBack: bool}
 */
function applyRfaNativePhpPatchSet(string $distRoot, string $stockRoot): array
{
    $result = [
        'applied' => [],
        'unchanged' => [],
        'blocked' => [],
        'stale' => [],
        'absent' => [],
        'written' => [],
        'error' => null,
        'rolledBack' => false,
    ];

    /** @var array<string, array{live: string, patched: string, stockPath: string, storeStock: bool, changed: list<string>, unchanged: list<string>}> $files */
    $files = [];

    foreach (rfaNativePhpPatchSet() as $patch) {
        $path = $distRoot.'/'.$patch['file'];

        if (! isset($files[$path])) {
            if (! is_file($path)) {
                $result['absent'][] = $patch['name'];

                continue;
            }

            $live = @file_get_contents($path);

            if ($live === false) {
                $result['blocked'][] = $patch['name'];

                continue;
            }

            // A target without rfa edits is stock, and becomes the stock copy
            // when the stored one is missing or differs (a reinstalled or
            // upgraded plugin). A patched target starts from its stored copy.
            $stockPath = $stockRoot.'/'.rfaStockKey($patch['file']);
            $stored = is_file($stockPath) ? @file_get_contents($stockPath) : false;
            $isPatched = str_contains($live, RFA_PATCH_MARKER);

            if ($isPatched && $stored === false) {
                $result['blocked'][] = $patch['name'];
                $result['stale'][] = $patch['name'];

                continue;
            }

            $files[$path] = [
                'live' => $live,
                'patched' => $isPatched ? $stored : $live,
                'stockPath' => $stockPath,
                'storeStock' => ! $isPatched && $stored !== $live,
                'changed' => [],
                'unchanged' => [],
            ];
        }

        $next = $patch['apply']($files[$path]['patched']);

        if ($next === null) {
            $result['blocked'][] = $patch['name'];

            continue;
        }

        $files[$path][$next === $files[$path]['patched'] ? 'unchanged' : 'changed'][] = $patch['name'];
        $files[$path]['patched'] = $next;
    }

    // All-or-nothing extends to the files themselves. The dist directory is
    // either there or it is not — a pruned release copy drops it whole — so a
    // tree holding only some of the targets is a broken install, and patching
    // the survivors would ship exactly the half-patched vendor this set exists
    // to prevent.
    if ($result['absent'] !== [] && $files !== []) {
        $result['blocked'] = [...$result['blocked'], ...$result['absent']];
    }

    if ($result['blocked'] !== []) {
        return $result;
    }

    /** @var array<string, string> $renamed */
    $renamed = [];

    foreach ($files as $path => $contents) {
        // A target already holding this revision's output reports every patch
        // as unchanged, whatever the in-memory run from stock had to do.
        if ($contents['patched'] === $contents['live']) {
            $result['unchanged'] = [...$result['unchanged'], ...$contents['changed'], ...$contents['unchanged']];
        } else {
            $result['applied'] = [...$result['applied'], ...$contents['changed']];
            $result['unchanged'] = [...$result['unchanged'], ...$contents['unchanged']];
        }

        if ($contents['storeStock'] && ! rfaStoreStockCopy($contents['stockPath'], $contents['live'])) {
            $result['error'] = $contents['stockPath'];
            $result['rolledBack'] = rfaRestoreFiles($renamed);

            return $result;
        }

        if ($contents['patched'] === $contents['live']) {
            continue;
        }

        if (! rfaWriteFileAtomically($path, $contents['patched'])) {
            $result['error'] = $path;
            $result['rolledBack'] = rfaRestoreFiles($renamed);

            return $result;
        }

        $renamed[$path] = $contents['live'];
        $result['written'][] = $path;
    }

    return $result;
}

/**
 * Where a target's stock copy lives under the stock root: its path relative
 * to the vendored `resources/electron` directory, with `..` folded away.
 */
function rfaStockKey(string $file): string
{
    $segments = [];

    foreach (explode('/', 'electron-plugin/dist/'.$file) as $segment) {
        if ($segment === '..') {
            array_pop($segments);

            continue;
        }

        if ($segment !== '' && $segment !== '.') {
            $segments[] = $segment;
        }
    }

    return implode('/', $segments);
}

/**
 * Store the stock text of a target, creating the directory it lives in.
 */
function rfaStoreStockCopy(string $stockPath, string $contents): bool
{
    if (! is_dir(dirname($stockPath)) && ! @mkdir(dirname($stockPath), 0755, true) && ! is_dir(dirname($stockPath))) {
        return false;
    }

    return rfaWriteFileAtomically($stockPath, $contents);
}

/**
 * Replace `$path` with `$contents` through a sibling temporary file, so the
 * file is either its old self or its new self and never a truncated mix.
 */
function rfaWriteFileAtomically(string $path, string $contents): bool
{
    $temporaryPath = $path.'.rfa-patch-'.getmypid().'-'.bin2hex(random_bytes(4));

    if (@file_put_contents($temporaryPath, $contents) !== strlen($contents)) {
        @unlink($temporaryPath);

        return false;
    }

    // rename() creates a new directory entry, so carry the original mode over
    // rather than leaving the file on the process umask.
    $mode = @fileperms($path);

    if ($mode !== false) {
        @chmod($temporaryPath, $mode & 0777);
    }

    if (! @rename($temporaryPath, $path)) {
        @unlink($temporaryPath);

        return false;
    }

    return true;
}

/**
 * Put back the files a failed run had already replaced.
 *
 * @param  array<string, string>  $originals  path => the bytes it held before
 * @return bool whether every file was restored
 */
function rfaRestoreFiles(array $originals): bool
{
    $restored = true;

    foreach ($originals as $path => $original) {
        $restored = rfaWriteFileAtomically($path, $original) && $restored;
    }

    return $restored;
}

/**
 * The dist directory of the vendored Electron plugin.
 */
function rfaNativePhpDistRoot(): string
{
    return dirname(__DIR__).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist';
}

/**
 * Where the stock copies of the patched files are kept: inside the vendored
 * package, so Composer drops them with the package, and outside its
 * `resources/electron` tree, which the build copies whole.
 */
function rfaNativePhpStockRoot(): string
{
    return dirname(__DIR__).'/vendor/nativephp/desktop/.rfa-stock';
}

// Run when executed directly (not when required by tests)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $outcome = applyRfaNativePhpPatchSet(rfaNativePhpDistRoot(), rfaNativePhpStockRoot());

    /** @var array<string, string> $summaries */
    $summaries = array_column(rfaNativePhpPatchSet(), 'summary', 'name');

    foreach ($outcome['applied'] as $name) {
        printf("  NativePHP patched (%s): %s.\n", $name, $summaries[$name]);
    }

    foreach ($outcome['unchanged'] as $name) {
        printf("  NativePHP already patched (%s).\n", $name);
    }

    foreach ($outcome['blocked'] as $name) {
        if (in_array($name, $outcome['stale'], true)) {
            fwrite(STDERR, sprintf(
                "  ERROR: the '%s' patch (%s) targets a file already patched by an earlier revision of this script that kept no stock copy, so NOTHING was patched. Reinstall the plugin to start from stock: rm -rf vendor/nativephp/desktop && composer install\n",
                $name,
                $summaries[$name],
            ));

            continue;
        }

        fwrite(STDERR, sprintf(
            "  ERROR: the '%s' patch (%s) could not be applied, so NOTHING was patched. The vendored NativePHP files changed shape or are incomplete — update scripts/patch-nativephp.php to match them, or reinstall nativephp/desktop.\n",
            $name,
            $summaries[$name],
        ));
    }

    if ($outcome['error'] !== null) {
        fwrite(STDERR, sprintf(
            "  ERROR: could not write %s. %s\n",
            $outcome['error'],
            $outcome['rolledBack']
                ? 'The files already written were restored.'
                : 'RESTORING THE EARLIER FILES ALSO FAILED — reinstall nativephp/desktop before building.',
        ));
    }

    // Fail the composer hook when a vendored file is present but no longer
    // matches, or when a write failed. `absent` is NOT fatal: the release build
    // re-runs this hook via `composer install --no-dev` on a pruned copy where
    // the plugin dist legitimately isn't at this path, and that must not break
    // the build (the bundled dist was already patched on the primary install).
    if ($outcome['blocked'] !== [] || $outcome['error'] !== null) {
        exit(1);
    }
}

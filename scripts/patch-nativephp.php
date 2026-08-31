<?php

/**
 * The NativePHP vendor changes RFA depends on, applied as one patch set.
 *
 * Seven edits across four compiled files in `nativephp/desktop`'s bundled
 * Electron plugin. They are not independent in practice: three of them rewrite
 * the same `dist/index.js`, and a build that ships some of them is a build
 * whose startup behaviour nobody has tested. So the set is all-or-nothing —
 * every expected source shape is checked, and every new file content computed,
 * before the first byte is written. If any edit no longer matches, nothing is
 * written at all and the composer hook fails.
 *
 * Runs automatically via composer post-autoload-dump and post-update-cmd.
 */

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
 * Show NativePHP windows only after Electron has rendered their first frame.
 *
 * NativePHP currently shows a hidden BrowserWindow from `did-finish-load`.
 * That event confirms navigation, but it does not confirm that Chromium has
 * rendered a frame. Electron's `ready-to-show` event is the paint barrier for
 * a BrowserWindow created with `show: false`.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchWindowReadyToShow(string $content): ?string
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
    // [rfa window readiness] Navigation can finish before Chromium presents a
    // frame. Keep the BrowserWindow hidden until Electron confirms first paint.
    window.once('ready-to-show', () => {
        if (state.noFocusOnRestart && window.isVisible()) {
            return;
        }
        window.show();
    });
JS;

    if (str_contains($content, $find)) {
        $content = str_replace($find, $replace, $content);
    }

    $fullyPatched = str_contains($content, '[rfa window readiness]')
        && str_contains($content, "window.once('ready-to-show'")
        && ! str_contains($content, $find);

    return $fullyPatched ? $content : null;
}

/**
 * Give every NativePHP BrowserWindow the resolved RFA background color.
 *
 * The renderer and splash both use RFA's light and dark background tokens.
 * NativePHP otherwise uses the value sent by PHP, which can disagree with the
 * persisted renderer appearance during the frame before HTML is presented.
 *
 * @return string|null the patched content, or null when the expected source
 *                     shape is gone
 */
function rfaPatchWindowTheme(string $content): ?string
{
    $importFind = "import { BrowserWindow } from 'electron';";
    $importReplace = "import { BrowserWindow, nativeTheme } from 'electron'; // [rfa window theme]";
    $backgroundFind = '        backgroundColor, transparent: transparency, alwaysOnTop,';
    $backgroundReplace = "        backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff', transparent: transparency, alwaysOnTop,";

    if (str_contains($content, $importFind)) {
        $content = str_replace($importFind, $importReplace, $content);
    }

    if (str_contains($content, $backgroundFind)) {
        $content = str_replace($backgroundFind, $backgroundReplace, $content);
    }

    $fullyPatched = str_contains($content, '[rfa window theme]')
        && str_contains($content, $backgroundReplace)
        && ! str_contains($content, $backgroundFind);

    return $fullyPatched ? $content : null;
}

/**
 * Speed up cold starts in NativePHP's Electron server bootstrap.
 *
 * Two independent edits to the compiled `dist/server/php.js` (the file
 * `electron-vite build` bundles directly — it does not run the plugin's `tsc`
 * step):
 *
 *  1. Optimize once per version, then skip the cache step on warm launches.
 *     NativePHP runs `php artisan optimize` synchronously before the PHP server
 *     starts, on every launch. That recompiles all Blade views and re-caches
 *     config/routes/events (~1s) and blocks the window. The compiled caches
 *     persist in the build's bootstrap/cache, so the full optimize is only
 *     needed on a version change (fresh install / post-update) or when a cache
 *     file is missing. On same-version launches the cache step is skipped
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
 * Both edits must land: this returns null unless every one of them is present
 * in the result, so a NativePHP bump that reshapes one block fails the patch
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
            // re-caches config/routes/events (~1s) and previously ran on every
            // launch, blocking the window. The compiled caches persist in the
            // build's bootstrap/cache, so the full optimize is only needed when
            // the app version changes (fresh install / post-update) or a cache
            // file is missing.
            //
            // On a same-version launch we skip the cache step ENTIRELY — including
            // the config:cache the earlier RFA patch ran for the fresh per-launch
            // API port and IPC secret. Those two values are the only per-launch
            // config that varies, and the app now re-reads them from the live
            // process environment at runtime (RehydrateNativeRuntimeConfigAction,
            // wired in bootstrap/app.php via a beforeBootstrapping(RegisterProviders)
            // hook that runs before any provider registers), so the persisted
            // version-cached config stays valid and we avoid a full framework boot
            // on every warm launch.
            //
            // Probe the caches at the directory Laravel actually writes them to
            // for this build type. NativePHP only redirects APP_*_CACHE into
            // userData/bootstrap/cache for a *secure* build; an unsecure build
            // (what `native:build` produces without a bundle — RFA's shipping
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
                console.log('Caching views, routes, and config...');
                let result = callPhpSync(['artisan', 'optimize'], phpOptions, phpIniSettings);
                if (result.status !== 0) {
                    console.error('Failed to cache framework bootstrap:', result.stderr.toString());
                }
                else {
                    store.set('optimized_version', app.getVersion());
                }
            }
        }
JS;

    // The optimize block as the PREVIOUS RFA revision left it: a same-version
    // launch re-ran `config:cache` via an rfaCommand ternary (no config.php
    // probe). The stock find above no longer matches such a file, so without
    // this an already-patched vendor copy would keep paying config:cache every
    // warm launch. Replacing the whole old block upgrades it to the current
    // skip-entirely shape, byte-identical to a fresh patch.
    $oldOptimizeFind = <<<'JS'
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
            //
            // Probe the route/event caches at the directory Laravel actually
            // writes them to for this build type. NativePHP only redirects
            // APP_ROUTES_CACHE/APP_EVENTS_CACHE into userData/bootstrap/cache
            // for a *secure* build; an unsecure build (what `native:build`
            // produces without a bundle — RFA's shipping shape) leaves them at
            // <appPath>/bootstrap/cache. Checking bootstrapCache unconditionally
            // would never find them in an unsecure build, so the gate would trip
            // every launch and pay the full optimize anyway.
            const rfaCacheDir = runningSecureBuild() ? bootstrapCache : join(getAppPath(), 'bootstrap', 'cache');
            const rfaVersionChanged = store.get('optimized_version') !== app.getVersion();
            const rfaNeedsFullOptimize = rfaVersionChanged
                || !existsSync(join(rfaCacheDir, 'routes-v7.php'))
                || !existsSync(join(rfaCacheDir, 'events.php'));
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

    $patched = $content;

    if (str_contains($patched, $optimizeFind)) {
        $patched = str_replace($optimizeFind, $optimizeReplace, $patched);
    }

    // Upgrade a file left patched by the previous RFA revision in place. Only one
    // of these two finds can match (stock OR old-patched), so this never double-
    // applies; on the current shape neither matches.
    if (str_contains($patched, $oldOptimizeFind)) {
        $patched = str_replace($oldOptimizeFind, $optimizeReplace, $patched);
    }

    if (str_contains($patched, $mkdirFind) && ! str_contains($patched, '[rfa opcache] persistent opcode cache dir')) {
        $patched = str_replace($mkdirFind, $mkdirReplace, $patched);
    }

    // Both pre-flight functions share this exact tail; str_replace handles both.
    if (str_contains($patched, $preflightFind)) {
        $patched = str_replace($preflightFind, $preflightReplace, $patched);
    }

    // Only report success when every edit is present in the result. The config.php
    // probe is the marker UNIQUE to the current skip-entirely shape — requiring it
    // (not just `rfaNeedsFullOptimize`, which the previous revision also had) means
    // a file still carrying the old config:cache warm-launch branch is treated as
    // not-yet-patched rather than mis-reported as already_patched. The two opcache
    // markers are each UNIQUE to their edit: the mkdir comment proves the cache
    // directory is created, and the pre-flight banner (×2) proves both retrieve*
    // helpers reuse it. (`'framework', 'opcache'` alone would be ambiguous — the
    // pre-flight `opcache.file_cache=…` path contains that same substring, so a
    // file with only the pre-flight edit could mis-report as fully patched while
    // the cache directory is never created.)
    $fullyPatched = str_contains($patched, "existsSync(join(rfaCacheDir, 'config.php'))")
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
 * real BrowserWindow (show:false) and only `.show()`s it on `did-finish-load`.
 * So the user stares at a blank screen for the entire Electron-boot + PHP-boot +
 * first-render duration — the screen is dark until everything is ready.
 *
 * This opens a lightweight, frameless splash the instant `app.whenReady()`
 * resolves — before the PHP server boots — giving immediate visual feedback, and
 * hands off seamlessly: the splash closes the moment the real window finishes
 * loading (the first non-splash window created, via Window::open). The splash is
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
    $htmlConst = <<<'JS'
// [rfa splash] Self-contained splash markup — inline styles only, no external
// resources, so it loads instantly from a data: URL with nothing to bundle. It
// theme-matches the OS (and thus RFA's default appearance) via prefers-color-scheme.
const RFA_SPLASH_HTML = `<!doctype html><html><head><meta charset="utf-8"><style>:root{--rfa-bg:#ffffff;--rfa-fg:#09090b;--rfa-track:rgba(9,9,11,.14);--rfa-accent:#3b82f6}@media (prefers-color-scheme:dark){:root{--rfa-bg:#09090b;--rfa-fg:#fafafa;--rfa-track:rgba(250,250,250,.18);--rfa-accent:#60a5fa}}html,body{margin:0;height:100%;background:var(--rfa-bg);overflow:hidden}.wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--rfa-fg);-webkit-user-select:none;user-select:none}.name{font-size:22px;font-weight:600;letter-spacing:.4px;opacity:.92}.spinner{margin-top:18px;width:26px;height:26px;border:3px solid var(--rfa-track);border-top-color:var(--rfa-accent);border-radius:50%;animation:rfaspin .8s linear infinite}@keyframes rfaspin{to{transform:rotate(360deg)}}</style></head><body><div class="wrap"><div class="name">rfa</div><div class="spinner"></div></div></body></html>`;
JS;

    // 3. The splash lifecycle methods, injected as class members.
    $methodsAnchor = '    bootstrapApp(app) {';
    $methods = <<<'JS'
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
                backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff',
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
            // splash on that window's `show` — which NativePHP fires only after
            // `did-finish-load` — so the real window is painted before the splash
            // disappears, leaving no blank frame in between.
            const rfaOnCreated = (_, window) => {
                if (window === this.rfaSplash) {
                    return;
                }
                app.removeListener('browser-window-created', rfaOnCreated);
                this.rfaSplashListener = null;
                // Close on `show` (painted — the seamless handoff) and on `closed`
                // (a window torn down before it ever shows — e.g. a failed load —
                // must not strand the splash until the 60s timer).
                window.once('show', () => this.rfaCloseSplash());
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
JS;

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
    $newSplashBgBlock = <<<'JS'
                show: false,
                skipTaskbar: true,
                // Tint the native window fill to the OS appearance so the frame
                // shown before the data: URL paints matches the splash content
                // (and RFA's default system-following theme) — no light/dark flash.
                backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff',
                title: 'rfa',
JS;
    if (str_contains($patched, $oldSplashBgBlock)) {
        $patched = str_replace($oldSplashBgBlock, $newSplashBgBlock, $patched);
    }

    // Only success when every edit is present, so a NativePHP bump that reshapes
    // one anchor can't half-apply (e.g. a splash that is created but never shown,
    // or themed markup without the nativeTheme import that tints the window).
    $fullyPatched = str_contains($patched, 'BrowserWindow, nativeTheme')
        && str_contains($patched, 'const RFA_SPLASH_HTML')
        && str_contains($patched, 'nativeTheme.shouldUseDarkColors')
        && str_contains($patched, 'rfaShowSplash() {')
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
    $methodsReplace = <<<'JS'
    rfaResolveAppearance() {
        return __awaiter(this, void 0, void 0, function* () {
            try {
                const rfaCookies = yield session.defaultSession.cookies.get({
                    url: 'http://127.0.0.1',
                    name: 'rfa_appearance',
                });
                const rfaAppearance = rfaCookies.length > 0 ? rfaCookies[0].value : 'system';
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
        return nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff';
    }
    rfaShowSplash() {
JS;

    $backgroundFind = "backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff',";
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

    if (str_contains($patched, $backgroundFind)) {
        $patched = str_replace($backgroundFind, $backgroundReplace, $patched);
    }

    if (str_contains($patched, $callFind)) {
        $patched = str_replace($callFind, $callReplace, $patched);
    }

    $fullyPatched = str_contains($patched, 'rfaResolveAppearance() {')
        && str_contains($patched, "name: 'rfa_appearance'")
        && str_contains($patched, "nativeTheme.themeSource = ['light', 'dark', 'system'].includes(rfaAppearance)")
        && str_contains($patched, 'rfaBackgroundColor() {')
        && str_contains($patched, $backgroundReplace)
        && str_contains($patched, 'yield this.rfaResolveAppearance(); // [rfa appearance]');

    return $fullyPatched ? $patched : null;
}

/**
 * The patch set: what has to be true of the vendored Electron plugin.
 *
 * Order matters within a file. The pre-flight cache, splash window, and
 * resolved appearance rewrite `dist/index.js`, and each is applied to the
 * result of the one before it.
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
            'name' => 'window-ready-to-show',
            'file' => 'server/api/window.js',
            'apply' => rfaPatchWindowReadyToShow(...),
            'summary' => 'windows wait for Electron first paint before showing',
        ],
        [
            'name' => 'window-theme',
            'file' => 'server/api/window.js',
            'apply' => rfaPatchWindowTheme(...),
            'summary' => 'window fills use the resolved RFA appearance',
        ],
        [
            'name' => 'server-optimize',
            'file' => 'server/php.js',
            'apply' => rfaPatchServerOptimize(...),
            'summary' => 'optimize once per version + opcache-warmed pre-flight boots',
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
    ];
}

/**
 * Apply the whole patch set under `$distRoot`, or none of it.
 *
 * Three phases, in order:
 *
 *  1. **Preflight.** Read each target once and run its edits in memory. A file
 *     that is missing entirely is reported as absent and skipped — the release
 *     build re-runs this hook over a pruned `--no-dev` copy where the plugin
 *     dist legitimately isn't there. A file that is present but whose expected
 *     shape is gone blocks the run.
 *  2. **Abort on any block.** Nothing has been written yet, so there is nothing
 *     to undo.
 *  3. **Write.** Each changed file goes to a sibling temporary file that is
 *     renamed into place, so a reader never sees a half-written file. If a
 *     later write fails, the files already renamed are restored from the
 *     originals held in memory.
 *
 * @return array{applied: list<string>, unchanged: list<string>, blocked: list<string>, absent: list<string>, written: list<string>, error: ?string, rolledBack: bool}
 */
function applyRfaNativePhpPatchSet(string $distRoot): array
{
    $result = [
        'applied' => [],
        'unchanged' => [],
        'blocked' => [],
        'absent' => [],
        'written' => [],
        'error' => null,
        'rolledBack' => false,
    ];

    /** @var array<string, array{original: string, patched: string}> $files */
    $files = [];

    foreach (rfaNativePhpPatchSet() as $patch) {
        $path = $distRoot.'/'.$patch['file'];

        if (! isset($files[$path])) {
            if (! is_file($path)) {
                $result['absent'][] = $patch['name'];

                continue;
            }

            $original = @file_get_contents($path);

            if ($original === false) {
                $result['blocked'][] = $patch['name'];

                continue;
            }

            $files[$path] = ['original' => $original, 'patched' => $original];
        }

        $next = $patch['apply']($files[$path]['patched']);

        if ($next === null) {
            $result['blocked'][] = $patch['name'];

            continue;
        }

        $result[$next === $files[$path]['patched'] ? 'unchanged' : 'applied'][] = $patch['name'];
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
        if ($contents['patched'] === $contents['original']) {
            continue;
        }

        if (! rfaWriteFileAtomically($path, $contents['patched'])) {
            $result['error'] = $path;
            $result['rolledBack'] = rfaRestoreFiles($renamed);

            return $result;
        }

        $renamed[$path] = $contents['original'];
        $result['written'][] = $path;
    }

    return $result;
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

// Run when executed directly (not when required by tests)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $outcome = applyRfaNativePhpPatchSet(rfaNativePhpDistRoot());

    /** @var array<string, string> $summaries */
    $summaries = array_column(rfaNativePhpPatchSet(), 'summary', 'name');

    foreach ($outcome['applied'] as $name) {
        printf("  NativePHP patched (%s): %s.\n", $name, $summaries[$name]);
    }

    foreach ($outcome['unchanged'] as $name) {
        printf("  NativePHP already patched (%s).\n", $name);
    }

    foreach ($outcome['blocked'] as $name) {
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

<?php

/**
 * Patch NativePHP's Electron server bootstrap for faster cold starts.
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
            // process environment at runtime (RehydrateNativeRuntimeConfigAction
            // in AppServiceProvider), so the persisted version-cached config stays
            // valid and we avoid a full framework boot on every warm launch.
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

    if (str_contains($patched, $mkdirFind) && ! str_contains($patched, "'framework', 'opcache'")) {
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
    // not-yet-patched rather than mis-reported as already_patched. The opcache
    // markers guard against a half-applied file; the pre-flight edit lands in both
    // retrieve* helpers.
    $fullyPatched = str_contains($patched, "existsSync(join(rfaCacheDir, 'config.php'))")
        && str_contains($patched, 'rfaNeedsFullOptimize')
        && str_contains($patched, "'framework', 'opcache'")
        && substr_count($patched, '[rfa opcache] reuse compiled opcode') === 2;

    if (! $fullyPatched) {
        return 'block_not_found';
    }

    if ($patched === $content) {
        return 'already_patched';
    }

    file_put_contents($serverPath, $patched);

    return 'patched';
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
 * @return 'patched'|'already_patched'|'block_not_found'|'not_found'
 */
function patchNativePreflightCache(string $indexPath): string
{
    if (! file_exists($indexPath)) {
        return 'not_found';
    }

    $content = file_get_contents($indexPath);

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

    if (! $fullyPatched) {
        return 'block_not_found';
    }

    if ($patched === $content) {
        return 'already_patched';
    }

    file_put_contents($indexPath, $patched);

    return 'patched';
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
 * @return 'patched'|'already_patched'|'block_not_found'|'not_found'
 */
function patchNativeSplashWindow(string $indexPath): string
{
    if (! file_exists($indexPath)) {
        return 'not_found';
    }

    $content = file_get_contents($indexPath);

    // 1. Pull BrowserWindow into the electron import so the splash can create one.
    $importFind = 'import { app, session, powerMonitor } from "electron";';
    $importReplace = 'import { app, session, powerMonitor, BrowserWindow } from "electron";';

    // 2. The splash markup, embedded as a module-level const (no asset to ship).
    $htmlAnchor = 'const { autoUpdater } = electronUpdater;';
    $htmlConst = <<<'JS'
// [rfa splash] Self-contained splash markup — inline styles only, no external
// resources, so it loads instantly from a data: URL with nothing to bundle.
const RFA_SPLASH_HTML = `<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;height:100%;background:#0d1117;overflow:hidden}.wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#e6edf3;-webkit-user-select:none;user-select:none}.name{font-size:22px;font-weight:600;letter-spacing:.4px;opacity:.92}.spinner{margin-top:18px;width:26px;height:26px;border:3px solid rgba(230,237,243,.18);border-top-color:#58a6ff;border-radius:50%;animation:rfaspin .8s linear infinite}@keyframes rfaspin{to{transform:rotate(360deg)}}</style></head><body><div class="wrap"><div class="name">rfa</div><div class="spinner"></div></div></body></html>`;
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
                backgroundColor: '#0d1117',
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
                window.once('show', () => this.rfaCloseSplash());
            };
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

    if (str_contains($patched, $importFind) && ! str_contains($patched, 'powerMonitor, BrowserWindow')) {
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

    // Only success when every edit is present, so a NativePHP bump that reshapes
    // one anchor can't half-apply (e.g. a splash that is created but never shown).
    $fullyPatched = str_contains($patched, 'powerMonitor, BrowserWindow')
        && str_contains($patched, 'const RFA_SPLASH_HTML')
        && str_contains($patched, 'rfaShowSplash() {')
        && str_contains($patched, 'this.rfaShowSplash()');

    if (! $fullyPatched) {
        return 'block_not_found';
    }

    if ($patched === $content) {
        return 'already_patched';
    }

    file_put_contents($indexPath, $patched);

    return 'patched';
}

// Run when executed directly (not when required by tests)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $electronPlugin = __DIR__.'/../vendor/nativephp/desktop/resources/electron/electron-plugin/dist';

    $result = patchNativeServerOptimize($electronPlugin.'/server/php.js');

    match ($result) {
        'patched' => print "  NativePHP server patched: optimize once per version + opcache warm boots.\n",
        'already_patched' => print "  NativePHP server already patched (optimize + opcache).\n",
        'block_not_found' => fwrite(STDERR, "  ERROR: NativePHP server bootstrap changed shape — startup patch NOT applied. Update scripts/patch-native-server.php to match the new dist/server/php.js.\n"),
        // not_found is benign: the release build runs `composer install --no-dev`
        // on a pruned copy where the electron-plugin dist isn't present at this
        // path, so the hook fires with nothing to patch. Skip silently — the real
        // vendor dist (which the build bundles) was patched on the primary install.
        'not_found' => null,
    };

    $preflightResult = patchNativePreflightCache($electronPlugin.'/index.js');

    match ($preflightResult) {
        'patched' => print "  NativePHP pre-flight cached: native:config / native:php-ini reused per version.\n",
        'already_patched' => print "  NativePHP pre-flight already cached.\n",
        'block_not_found' => fwrite(STDERR, "  ERROR: NativePHP main bootstrap changed shape — pre-flight cache NOT applied. Update scripts/patch-native-server.php to match the new dist/index.js.\n"),
        'not_found' => null,
    };

    $splashResult = patchNativeSplashWindow($electronPlugin.'/index.js');

    match ($splashResult) {
        'patched' => print "  NativePHP splash window added: instant feedback before PHP boots.\n",
        'already_patched' => print "  NativePHP splash window already present.\n",
        'block_not_found' => fwrite(STDERR, "  ERROR: NativePHP main bootstrap changed shape — splash window NOT applied. Update scripts/patch-native-server.php to match the new dist/index.js.\n"),
        'not_found' => null,
    };

    // Fail the composer hook only when a vendored file is present but no longer
    // matches (block_not_found): a NativePHP bump that reshaped the bootstrap must
    // not silently ship without the startup optimizations. `not_found` is NOT
    // fatal — the release build re-runs this hook via `composer install --no-dev`
    // on a pruned copy where the dist legitimately isn't at this path, and that
    // must not break the build (the bundled dist was already patched on install).
    if ($result === 'block_not_found' || $preflightResult === 'block_not_found' || $splashResult === 'block_not_found') {
        exit(1);
    }
}

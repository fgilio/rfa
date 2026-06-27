<?php

require_once dirname(__DIR__, 3).'/scripts/patch-native-server.php';

// -- Fixture: stock NativePHP server bootstrap (unpatched optimize block) --

function stockServer(): string
{
    return <<<'JS'
mkdirpSync(join(storagePath, 'framework', 'sessions'));
mkdirpSync(join(storagePath, 'framework', 'views'));
mkdirpSync(join(storagePath, 'framework', 'testing'));
function retrievePhpIniSettings() {
    return __awaiter(this, void 0, void 0, function* () {
        let command = ['artisan', 'native:php-ini'];
        if (runningSecureBuild()) {
            command.unshift(join(appPath, 'build', '__nativephp_app_bundle'));
        }
        return yield promisify(execFile)(state.php, command, phpOptions);
    });
}
function retrieveNativePHPConfig() {
    return __awaiter(this, void 0, void 0, function* () {
        let command = ['artisan', 'native:config'];
        if (runningSecureBuild()) {
            command.unshift(join(appPath, 'build', '__nativephp_app_bundle'));
        }
        return yield promisify(execFile)(state.php, command, phpOptions);
    });
}
        if (env.NIGHTWATCH_INGEST_URI && phpNightWatchPort) {
            console.log('Starting Nightwatch server...');
        }
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
        if (shouldMigrateDatabase(store)) {
            console.log('Migrating database...');
        }
JS;
}

function tempServer(?string $content = null): string
{
    $dir = sys_get_temp_dir().'/rfa_test_server_'.getmypid().'_'.uniqid('', true);
    mkdir($dir, 0755, true);
    $path = $dir.'/php.js';

    if ($content !== null) {
        file_put_contents($path, $content);
    }

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/rfa_test_server_'.getmypid().'_*', GLOB_ONLYDIR) as $dir) {
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
});

// -- Missing file --

test('returns not_found when server file does not exist', function () {
    $path = tempServer(); // dir exists but file does not

    expect(patchNativeServerOptimize($path))->toBe('not_found');
});

// -- Block not found --

test('returns block_not_found when the optimize block is missing', function () {
    $path = tempServer('const something = "no optimize block here";');

    expect(patchNativeServerOptimize($path))->toBe('block_not_found');

    // File should be unchanged
    expect(file_get_contents($path))->toBe('const something = "no optimize block here";');
});

test('returns block_not_found when only some edits can apply (partial patch)', function () {
    // A file where the optimize block exists but the opcache anchors do not —
    // e.g. a NativePHP bump reshaped the mkdir / pre-flight blocks. The optimize
    // edit alone must NOT be reported as already_patched, or the opcache warm-up
    // would silently vanish. The file must also be left untouched.
    $optimizeOnly = <<<'JS'
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
    $path = tempServer($optimizeOnly);

    expect(patchNativeServerOptimize($path))->toBe('block_not_found');
    expect(file_get_contents($path))->toBe($optimizeOnly);
});

// -- Fresh patch --

test('patches the optimize block and returns patched', function () {
    $path = tempServer(stockServer());

    expect(patchNativeServerOptimize($path))->toBe('patched');

    $content = file_get_contents($path);

    expect($content)
        ->toContain('[rfa patch]')
        ->toContain('const rfaNeedsFullOptimize')
        // The full optimize only runs behind the version/cache gate; the warm
        // path falls through with no cache step at all.
        ->toContain('if (rfaNeedsFullOptimize) {')
        // The cache dir is build-type aware: userData/bootstrap/cache for a
        // secure build, <appPath>/bootstrap/cache for an unsecure one. Probing
        // bootstrapCache unconditionally would never trip the gate in an
        // unsecure build (RFA's shipping shape), paying the full optimize.
        ->toContain("const rfaCacheDir = runningSecureBuild() ? bootstrapCache : join(getAppPath(), 'bootstrap', 'cache')")
        // The config cache is probed too: skipping config:cache on warm launches
        // is only safe while a persisted config.php is present to fall back on.
        ->toContain("existsSync(join(rfaCacheDir, 'config.php'))")
        ->toContain("existsSync(join(rfaCacheDir, 'routes-v7.php'))")
        ->toContain("existsSync(join(rfaCacheDir, 'events.php'))");
});

test('warms the pre-flight artisan calls with a persistent opcache file cache', function () {
    $path = tempServer(stockServer());

    patchNativeServerOptimize($path);
    $content = file_get_contents($path);

    expect($content)
        // Cache directory created at module load, before any PHP call runs.
        ->toContain("mkdirpSync(join(storagePath, 'framework', 'opcache'))")
        // Both native:php-ini and native:config get the opcache flags.
        ->toContain("command.unshift('-d', 'opcache.enable_cli=1'");

    expect(substr_count($content, '[rfa opcache] reuse compiled opcode'))->toBe(2);
});

test('fails loudly when the opcache cache-dir mkdir anchor is reshaped, despite the pre-flight substring matching', function () {
    // Simulate a NativePHP bump that reshapes the `framework/testing` mkdir anchor
    // so the cache-dir mkdir edit can no longer land, while the pre-flight anchors
    // still match and inject `opcache.file_cache=…join(storagePath,'framework','opcache')`.
    // That path CONTAINS the literal "'framework', 'opcache'", so a success check
    // keyed on that ambiguous substring would mis-report this half-applied file as
    // fully patched — shipping a build whose opcache file-cache directory is never
    // created. The gate keys on the mkdir line's UNIQUE marker, so it must fail loudly.
    $reshaped = str_replace(
        "mkdirpSync(join(storagePath, 'framework', 'testing'));",
        "mkdirpSync(join(storagePath, 'framework', 'cache'));",
        stockServer(),
    );
    $path = tempServer($reshaped);

    expect(patchNativeServerOptimize($path))->toBe('block_not_found');
    // block_not_found must not leave a half-applied file on disk.
    expect(file_get_contents($path))->toBe($reshaped);
});

test('the cache step only runs behind the version gate after patching', function () {
    $path = tempServer(stockServer());

    patchNativeServerOptimize($path);
    $content = file_get_contents($path);

    // The stock build ran `optimize` unconditionally on every launch. After
    // patching the only `optimize` call lives inside `if (rfaNeedsFullOptimize)`,
    // and the warm path runs no cache step at all — not even config:cache (which
    // the previous patch revision still paid every launch).
    expect($content)
        ->toContain('if (rfaNeedsFullOptimize) {')
        ->toContain("callPhpSync(['artisan', 'optimize'], phpOptions, phpIniSettings)")
        ->not->toContain("'artisan', 'config:cache'")
        ->not->toContain('rfaCommand');
});

// -- Upgrading a file patched by the previous RFA revision --

// The full optimize block the CURRENT patch injects (comment + code), and the
// full block the PREVIOUS revision injected (config:cache warm branch). Used to
// synthesize a faithfully old-patched file from the current patch output.
function currentServerOptimizeBlock(): string
{
    return <<<'JS'
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
}

function previousRevisionServerOptimizeBlock(): string
{
    return <<<'JS'
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
}

// Build a file exactly as the previous revision left it: the current patch
// output (real opcache edits) with the optimize block reverted to old config:cache.
function oldPatchedServer(): string
{
    $path = tempServer(stockServer());
    patchNativeServerOptimize($path);
    $current = file_get_contents($path);

    // Guard: if the current patch reshaped, this revert would silently no-op and
    // the "old" fixture would actually be the new shape. Assert it really swaps.
    expect($current)->toContain(currentServerOptimizeBlock());

    return str_replace(currentServerOptimizeBlock(), previousRevisionServerOptimizeBlock(), $current);
}

test('a file patched by the previous revision is NOT mistaken for already_patched', function () {
    // The old block still carries `rfaNeedsFullOptimize` and the opcache markers,
    // so the pre-config.php-probe check would have returned already_patched and
    // left the config:cache-every-warm-launch branch in place.
    $old = oldPatchedServer();

    expect($old)
        ->toContain('rfaCommand')
        ->not->toContain("existsSync(join(rfaCacheDir, 'config.php'))");
});

test('upgrades a previously-patched file to the skip-entirely shape', function () {
    $path = tempServer(oldPatchedServer());

    expect(patchNativeServerOptimize($path))->toBe('patched');

    $content = file_get_contents($path);

    expect($content)
        // The old config:cache warm-launch branch is gone…
        ->not->toContain('rfaCommand')
        ->not->toContain("'artisan', 'config:cache'")
        // …replaced by the current skip-entirely gate.
        ->toContain("existsSync(join(rfaCacheDir, 'config.php'))")
        ->toContain('if (rfaNeedsFullOptimize) {');

    // The upgrade is byte-identical to a fresh stock → current patch.
    $fresh = tempServer(stockServer());
    patchNativeServerOptimize($fresh);
    expect($content)->toBe(file_get_contents($fresh));
});

test('upgrading a previously-patched file is idempotent', function () {
    $path = tempServer(oldPatchedServer());

    patchNativeServerOptimize($path);

    expect(patchNativeServerOptimize($path))->toBe('already_patched');
});

// -- Idempotency --

test('returns already_patched on second run', function () {
    $path = tempServer(stockServer());

    patchNativeServerOptimize($path);

    expect(patchNativeServerOptimize($path))->toBe('already_patched');
});

test('does not duplicate the patch on repeated runs', function () {
    $path = tempServer(stockServer());

    patchNativeServerOptimize($path);
    $contentAfterFirst = file_get_contents($path);

    patchNativeServerOptimize($path);
    $contentAfterSecond = file_get_contents($path);

    expect($contentAfterSecond)->toBe($contentAfterFirst);
});

// -- Content integrity --

test('preserves the surrounding server code', function () {
    $path = tempServer(stockServer());

    patchNativeServerOptimize($path);
    $content = file_get_contents($path);

    expect($content)
        ->toContain("console.log('Starting Nightwatch server...')")
        ->toContain("console.log('Migrating database...')")
        // Version bookkeeping is retained, just gated behind a full optimize.
        ->toContain("store.set('optimized_version', app.getVersion())");
});

// -- Pre-flight cache (dist/index.js) --

function stockIndex(): string
{
    return <<<'JS'
import electronUpdater from 'electron-updater';
class App {
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
}
JS;
}

test('pre-flight: returns not_found when index file does not exist', function () {
    expect(patchNativePreflightCache(tempServer()))->toBe('not_found');
});

test('pre-flight: returns block_not_found when the load methods are missing', function () {
    $path = tempServer('const x = 1;');

    expect(patchNativePreflightCache($path))->toBe('block_not_found');
    expect(file_get_contents($path))->toBe('const x = 1;');
});

test('pre-flight: caches native:config and native:php-ini per app version, fail-open', function () {
    $path = tempServer(stockIndex());

    expect(patchNativePreflightCache($path))->toBe('patched');

    $content = file_get_contents($path);

    expect($content)
        ->toContain('import Store from "electron-store"; // [rfa preflight cache]')
        ->toContain("const rfaKey = 'preflight_config_' + app.getVersion();")
        ->toContain("const rfaKey = 'preflight_phpini_' + app.getVersion();")
        // Dot-notation off so the dotted version (preflight_config_1.0.0) stays a
        // literal key instead of nesting into {preflight_config_1:{0:{0:…}}}.
        ->toContain("new Store({ name: 'nativephp', accessPropertiesByDotNotation: false })")
        // Gated off in development so a dev always sees fresh config.
        ->toContain("process.env.NODE_ENV !== 'development'")
        // Fail open: the live retrieve* call is still present as the fallback.
        ->toContain('yield retrieveNativePHPConfig()')
        ->toContain('yield retrievePhpIniSettings()');

    // The Store import is added exactly once.
    expect(substr_count($content, 'import Store from "electron-store"; // [rfa preflight cache]'))->toBe(1);
});

test('pre-flight: returns block_not_found when only one method can be patched (partial)', function () {
    // Only loadConfig present — loadPhpIni reshaped by a NativePHP bump. Must not
    // report success with the pre-flight cache half-applied.
    $configOnly = <<<'JS'
import electronUpdater from 'electron-updater';
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
    $path = tempServer($configOnly);

    expect(patchNativePreflightCache($path))->toBe('block_not_found');
    expect(file_get_contents($path))->toBe($configOnly);
});

test('pre-flight: is idempotent', function () {
    $path = tempServer(stockIndex());

    patchNativePreflightCache($path);
    $first = file_get_contents($path);

    expect(patchNativePreflightCache($path))->toBe('already_patched');
    expect(file_get_contents($path))->toBe($first);
});

test('the vendored NativePHP main bootstrap carries the pre-flight cache', function () {
    $indexPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js';

    expect(file_get_contents($indexPath))
        ->toContain("'preflight_config_'")
        ->toContain("'preflight_phpini_'")
        ->toContain('import Store from "electron-store"; // [rfa preflight cache]');
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js'), 'NativePHP desktop electron plugin not installed');

// -- Splash window (dist/index.js) --

function stockIndexForSplash(): string
{
    return <<<'JS'
import { app, session, powerMonitor } from "electron";
import electronUpdater from 'electron-updater';
const { autoUpdater } = electronUpdater;
class NativePHP {
    registerListeners(app) {
        app.on("browser-window-created", (_, window) => {
            optimizer.watchWindowShortcuts(window);
        });
    }
    bootstrapApp(app) {
        return __awaiter(this, void 0, void 0, function* () {
            yield app.whenReady();
            const config = yield this.loadConfig();
            yield this.startPhpApp();
            yield notifyLaravel("booted");
        });
    }
}
JS;
}

test('splash: returns not_found when index file does not exist', function () {
    expect(patchNativeSplashWindow(tempServer()))->toBe('not_found');
});

test('splash: returns block_not_found when the bootstrap anchors are missing', function () {
    $path = tempServer('const x = 1;');

    expect(patchNativeSplashWindow($path))->toBe('block_not_found');
    expect(file_get_contents($path))->toBe('const x = 1;');
});

test('splash: opens an early window before PHP boots, hands off, and is fail-open', function () {
    $path = tempServer(stockIndexForSplash());

    expect(patchNativeSplashWindow($path))->toBe('patched');

    $content = file_get_contents($path);

    expect($content)
        // BrowserWindow + nativeTheme are pulled into the electron import so the
        // splash can open and tint itself to the OS appearance.
        ->toContain('import { app, session, powerMonitor, BrowserWindow, nativeTheme } from "electron";')
        // The markup is embedded as a self-contained data URL — nothing to bundle.
        ->toContain('const RFA_SPLASH_HTML')
        ->toContain("loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(RFA_SPLASH_HTML))")
        // Fired the instant Electron is ready, before any PHP boot.
        ->toContain('this.rfaShowSplash(); // [rfa splash] instant feedback before PHP boots')
        // Seamless handoff: the splash closes once the real window is shown.
        ->toContain("window.once('show', () => this.rfaCloseSplash())")
        // Fail-open: splash creation is wrapped so any error just means no splash.
        ->toContain('catch (rfaError)');

    // The splash fires before the first PHP boot (loadConfig), not after.
    expect(strpos($content, 'this.rfaShowSplash()'))
        ->toBeLessThan(strpos($content, 'const config = yield this.loadConfig()'));

    // Each edit lands exactly once.
    expect(substr_count($content, 'powerMonitor, BrowserWindow'))->toBe(1)
        ->and(substr_count($content, 'rfaShowSplash() {'))->toBe(1)
        ->and(substr_count($content, 'const RFA_SPLASH_HTML'))->toBe(1);
});

test('splash: follows the OS light/dark appearance (matches RFA, which follows the system)', function () {
    $path = tempServer(stockIndexForSplash());

    patchNativeSplashWindow($path);

    expect(file_get_contents($path))
        // nativeTheme is imported so the native window fill can be tinted.
        ->toContain('powerMonitor, BrowserWindow, nativeTheme')
        // The native window backgroundColor tracks the OS appearance (no flash):
        // dark fill on a dark OS, light fill on a light OS.
        ->toContain("backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff'")
        // The splash content themes ITSELF via prefers-color-scheme — a data: URL
        // follows nativeTheme, so the page flips palette with the OS automatically.
        ->toContain('@media (prefers-color-scheme:dark)')
        // Light palette is the default (RFA's light tokens); dark overrides it.
        ->toContain('--rfa-bg:#ffffff')
        ->toContain('--rfa-bg:#09090b')
        // The old hardcoded GitHub-dark fill is gone (it matched neither RFA mode).
        ->not->toContain('#0d1117');
});

// -- Upgrading a file left splash-patched by the previous (dark-only) revision --

// Reconstruct a file exactly as the pre-theme splash revision left it: patch
// stock, then reverse the three theme edits back to the dark-only shape.
function oldThemedSplashServer(): string
{
    $path = tempServer(stockIndexForSplash());
    patchNativeSplashWindow($path);
    $themed = file_get_contents($path);

    $newHtmlBlock = <<<'JS'
// [rfa splash] Self-contained splash markup — inline styles only, no external
// resources, so it loads instantly from a data: URL with nothing to bundle. It
// theme-matches the OS (and thus RFA's default appearance) via prefers-color-scheme.
const RFA_SPLASH_HTML = `<!doctype html><html><head><meta charset="utf-8"><style>:root{--rfa-bg:#ffffff;--rfa-fg:#09090b;--rfa-track:rgba(9,9,11,.14);--rfa-accent:#3b82f6}@media (prefers-color-scheme:dark){:root{--rfa-bg:#09090b;--rfa-fg:#fafafa;--rfa-track:rgba(250,250,250,.18);--rfa-accent:#60a5fa}}html,body{margin:0;height:100%;background:var(--rfa-bg);overflow:hidden}.wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--rfa-fg);-webkit-user-select:none;user-select:none}.name{font-size:22px;font-weight:600;letter-spacing:.4px;opacity:.92}.spinner{margin-top:18px;width:26px;height:26px;border:3px solid var(--rfa-track);border-top-color:var(--rfa-accent);border-radius:50%;animation:rfaspin .8s linear infinite}@keyframes rfaspin{to{transform:rotate(360deg)}}</style></head><body><div class="wrap"><div class="name">rfa</div><div class="spinner"></div></div></body></html>`;
JS;
    $oldHtmlBlock = <<<'JS'
// [rfa splash] Self-contained splash markup — inline styles only, no external
// resources, so it loads instantly from a data: URL with nothing to bundle.
const RFA_SPLASH_HTML = `<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;height:100%;background:#0d1117;overflow:hidden}.wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#e6edf3;-webkit-user-select:none;user-select:none}.name{font-size:22px;font-weight:600;letter-spacing:.4px;opacity:.92}.spinner{margin-top:18px;width:26px;height:26px;border:3px solid rgba(230,237,243,.18);border-top-color:#58a6ff;border-radius:50%;animation:rfaspin .8s linear infinite}@keyframes rfaspin{to{transform:rotate(360deg)}}</style></head><body><div class="wrap"><div class="name">rfa</div><div class="spinner"></div></div></body></html>`;
JS;
    $newBg = <<<'JS'
                show: false,
                skipTaskbar: true,
                // Tint the native window fill to the OS appearance so the frame
                // shown before the data: URL paints matches the splash content
                // (and RFA's default system-following theme) — no light/dark flash.
                backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff',
                title: 'rfa',
JS;
    $oldBg = <<<'JS'
                show: false,
                skipTaskbar: true,
                backgroundColor: '#0d1117',
                title: 'rfa',
JS;

    // Guard: if the current patch reshaped any of these, the reversal would
    // silently no-op and the "old" fixture would actually be the new shape.
    expect($themed)
        ->toContain('powerMonitor, BrowserWindow, nativeTheme')
        ->toContain($newHtmlBlock)
        ->toContain($newBg);

    $old = str_replace('powerMonitor, BrowserWindow, nativeTheme', 'powerMonitor, BrowserWindow', $themed);
    $old = str_replace($newHtmlBlock, $oldHtmlBlock, $old);

    return str_replace($newBg, $oldBg, $old);
}

test('splash: a dark-only patched file is NOT mistaken for fully patched (would fail the gate)', function () {
    // The old splash carries BrowserWindow + rfaShowSplash but no nativeTheme, so
    // the success gate must treat it as not-current rather than already_patched.
    $old = oldThemedSplashServer();

    expect($old)
        ->toContain('#0d1117')
        ->not->toContain('nativeTheme');
});

test('splash: upgrades a previously dark-only splash to the OS-following themed shape', function () {
    $path = tempServer(oldThemedSplashServer());

    expect(patchNativeSplashWindow($path))->toBe('patched');

    $content = file_get_contents($path);

    expect($content)
        ->toContain('powerMonitor, BrowserWindow, nativeTheme')
        ->toContain('@media (prefers-color-scheme:dark)')
        ->toContain("backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff'")
        ->not->toContain('#0d1117');

    // The upgrade is byte-identical to a fresh stock → themed patch.
    $fresh = tempServer(stockIndexForSplash());
    patchNativeSplashWindow($fresh);
    expect($content)->toBe(file_get_contents($fresh));
});

test('splash: upgrading a previously dark-only splash is idempotent', function () {
    $path = tempServer(oldThemedSplashServer());

    patchNativeSplashWindow($path);

    expect(patchNativeSplashWindow($path))->toBe('already_patched');
});

test('splash: preserves the existing browser-window-created listener', function () {
    $path = tempServer(stockIndexForSplash());

    patchNativeSplashWindow($path);

    // The patch adds its own handoff listener without clobbering NativePHP's.
    expect(file_get_contents($path))
        ->toContain('optimizer.watchWindowShortcuts(window)');
});

test('splash: cleans up its window-created listener and closes on a torn-down window', function () {
    $path = tempServer(stockIndexForSplash());

    patchNativeSplashWindow($path);

    expect(file_get_contents($path))
        // The handoff listener is retained on the instance so it can be removed…
        ->toContain('this.rfaSplashListener = rfaOnCreated')
        // …and rfaCloseSplash removes it on the timeout path (no main window ever
        // opened), so the closure — and the App instance it captures — can't leak
        // for the life of the process.
        ->toContain("app.removeListener('browser-window-created', this.rfaSplashListener)")
        // A window torn down before it ever fires `show` (e.g. a failed load) still
        // hands off, instead of stranding the splash until the 60s safety timer.
        ->toContain("window.once('closed', () => this.rfaCloseSplash())");
});

test('splash: is idempotent', function () {
    $path = tempServer(stockIndexForSplash());

    patchNativeSplashWindow($path);
    $first = file_get_contents($path);

    expect(patchNativeSplashWindow($path))->toBe('already_patched');
    expect(file_get_contents($path))->toBe($first);
});

test('splash: returns block_not_found when only the import anchor is present (partial)', function () {
    // A NativePHP bump that reshaped the class but left the electron import. The
    // import edit alone must not report success with the splash half-applied, and
    // the file must be left untouched.
    $importOnly = 'import { app, session, powerMonitor } from "electron";';
    $path = tempServer($importOnly);

    expect(patchNativeSplashWindow($path))->toBe('block_not_found');
    expect(file_get_contents($path))->toBe($importOnly);
});

test('the vendored NativePHP main bootstrap carries the splash window', function () {
    $indexPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js';

    expect(file_get_contents($indexPath))
        ->toContain('powerMonitor, BrowserWindow')
        ->toContain('const RFA_SPLASH_HTML')
        ->toContain('this.rfaShowSplash()');
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js'), 'NativePHP desktop electron plugin not installed');

// -- Applied to the real vendored file --

test('the vendored NativePHP server carries the optimize patch', function () {
    $serverPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js';

    // post-autoload-dump applies this patch on install. Assert the markers are
    // present (non-mutating) so a NativePHP bump that reshapes a block — making
    // an edit silently no-op and shipping an every-launch optimize / cold
    // pre-flight boots again — fails loudly here instead.
    expect(file_get_contents($serverPath))
        ->toContain('rfaNeedsFullOptimize')
        ->toContain("mkdirpSync(join(storagePath, 'framework', 'opcache'))")
        ->toContain("command.unshift('-d', 'opcache.enable_cli=1'");
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js'), 'NativePHP desktop electron plugin not installed');

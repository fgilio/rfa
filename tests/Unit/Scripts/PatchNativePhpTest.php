<?php

require_once dirname(__DIR__, 3).'/scripts/patch-nativephp.php';
require_once dirname(__DIR__, 2).'/Helpers/native-php-dist-fixtures.php';

/*
 * Per-edit coverage for the transformers in scripts/patch-nativephp.php. Each
 * one takes the current file contents and returns the patched contents, or
 * null when the shape it edits is gone. `NativePhpPatchSetTest` covers the
 * runner that applies them together.
 */

test('startup palette reads and validates the theme config', function () {
    $theme = require dirname(__DIR__, 3).'/config/theme.php';

    expect(rfaThemeRgb('light', 'bg'))->toBe(array_map('intval', explode(' ', $theme['colors']['light']['bg'])))
        ->and(rfaThemeRgb('dark', 'text'))->toBe(array_map('intval', explode(' ', $theme['colors']['dark']['text'])))
        ->and(rfaThemeHex('light', 'link'))->toBe('#3b82f6')
        ->and(rfaThemeRgba('dark', 'text', '.18'))->toBe('rgba(250,250,250,.18)');
});

test('startup palette rejects invalid RGB triples', function (mixed $value, string $message) {
    expect(fn () => rfaParseThemeRgb($value, 'colors.light.bg'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'wrong shape' => ['255 255', 'must be an RGB triple'],
    'wrong type' => [null, 'must be an RGB triple'],
    'channel out of range' => ['256 255 255', 'must contain channels from 0 to 255'],
]);

// ===== preload/index.mjs: the drag-and-drop file bridge =====

// -- Shape changes --

test('reports a shape change when the electron import line is missing', function () {
    expect(rfaPatchPreloadFileBridge('const something = "no electron import here";'))->toBeNull();
});

test('reports a shape change when the bridge is exposed without the webUtils import', function () {
    // Half-applied: an earlier run added the exposure but a NativePHP bump has
    // since reshaped the import it depends on. Refusing here is what keeps the
    // patch set from writing anything.
    $halfApplied = stockPreload()
        ."\ncontextBridge.exposeInMainWorld('nativeGetFilePath', (file) => getPathForFile(file));";

    expect(rfaPatchPreloadFileBridge($halfApplied))->toBeNull();
});

// -- Fresh patch --

test('patches an unpatched preload', function () {
    $content = rfaPatchPreloadFileBridge(stockPreload());

    expect($content)
        ->toContain('import { ipcRenderer, contextBridge, webUtils } from "electron";')
        ->toContain("contextBridge.exposeInMainWorld('nativeGetFilePath'")
        ->toContain('webUtils.getPathForFile(file)')
        ->toContain('[rfa patch]');
});

test('replaces the original import without duplicating it', function () {
    expect(substr_count((string) rfaPatchPreloadFileBridge(stockPreload()), 'from "electron"'))->toBe(1);
});

// -- Idempotency --

test('a second run leaves an already-patched preload untouched', function () {
    $patched = rfaPatchPreloadFileBridge(stockPreload());

    expect(rfaPatchPreloadFileBridge($patched))->toBe($patched);
});

// -- Content integrity --

test('preserves existing preload code', function () {
    $content = rfaPatchPreloadFileBridge(stockPreload());

    expect($content)
        ->toContain('import remote from "@electron/remote"')
        ->toContain("contextBridge.exposeInMainWorld('Native', Native)")
        ->toContain('contextMenu: (template)');
});

test('renderer readiness preload: reports a shape change when the Native bridge is missing', function () {
    expect(rfaPatchPreloadRendererReady('const x = 1;'))->toBeNull();
});

test('renderer readiness preload: exposes one fixed payload-free IPC signal', function () {
    $content = rfaPatchPreloadRendererReady(stockPreload());

    expect($content)
        ->toContain('[rfa renderer readiness]')
        ->toContain("contextBridge.exposeInMainWorld('nativeRendererReady', () => ipcRenderer.send('rfa:renderer-ready'))")
        ->not->toContain('ipcRenderer.send(channel');
});

test('renderer readiness preload: is idempotent', function () {
    $patched = rfaPatchPreloadRendererReady(stockPreload());

    expect(rfaPatchPreloadRendererReady($patched))->toBe($patched);
});

test('the vendored NativePHP preload exposes renderer readiness', function () {
    $preloadPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/preload/index.mjs';

    expect(file_get_contents($preloadPath))
        ->toContain("exposeInMainWorld('nativeRendererReady'")
        ->toContain("ipcRenderer.send('rfa:renderer-ready')");
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/preload/index.mjs'), 'NativePHP desktop electron plugin not installed');

// ===== server/php.js and index.js: boot-path edits =====

test('window theme: reports a shape change when the BrowserWindow options are missing', function () {
    expect(rfaPatchWindowTheme("import { BrowserWindow } from 'electron';"))->toBeNull();
});

test('window theme: uses the resolved native appearance for every BrowserWindow fill', function () {
    $content = rfaPatchWindowTheme(stockWindowApi());

    expect($content)
        ->toContain("import { BrowserWindow, nativeTheme } from 'electron'; // [rfa window theme]")
        ->toContain("backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff'")
        ->toContain("opacity: id === 'main' ? 0 : 1")
        ->not->toContain('        backgroundColor, transparent: transparency, alwaysOnTop,');
});

test('window theme: is idempotent', function () {
    $patched = rfaPatchWindowTheme(stockWindowApi());

    expect(rfaPatchWindowTheme($patched))->toBe($patched);
});

test('the vendored NativePHP window API uses the resolved appearance', function () {
    $windowPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/api/window.js';

    expect(file_get_contents($windowPath))
        ->toContain('[rfa window theme]')
        ->toContain("backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff'");
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/api/window.js'), 'NativePHP desktop electron plugin not installed');

test('renderer readiness window: reports a shape change when the load listener is missing', function () {
    expect(rfaPatchRendererReadyWindow('const x = 1;'))->toBeNull();
});

test('renderer readiness window: waits for the settled main renderer presentation', function () {
    $content = rfaPatchRendererReadyWindow(stockWindowApi());

    expect($content)
        ->toContain("let rfaPresentationPhase = id === 'main' ? 'waiting-renderer' : 'waiting-paint'")
        ->toContain("rfaPresentationPhase = 'presented'")
        ->toContain("channel !== 'rfa:renderer-ready'")
        ->not->toContain('beginFrameSubscription')
        ->toContain('window.setOpacity(1)')
        ->toContain("window.emit('rfa:presented')")
        ->toContain('window.rfaRequestShow = (focus = false)')
        ->toContain('rfaReadinessTimer = setTimeout(rfaPresent, rfaReadinessTimeoutMs)');
});

test('renderer readiness window: repeat opens cannot bypass the barrier', function () {
    $content = rfaPatchRendererReadyWindow(stockWindowApi());

    expect($content)
        ->toContain('const existingWindow = state.windows[id]')
        ->toContain("typeof existingWindow.rfaRequestShow === 'function'")
        ->toContain('existingWindow.rfaRequestShow(true)')
        ->not->toContain("if (state.windows[id]) {\n        state.windows[id].show();\n        state.windows[id].focus();");
});

test('renderer readiness window: explicit show requests cannot bypass the barrier', function () {
    $content = rfaPatchRendererReadyWindow(stockWindowApi());

    expect($content)
        ->toContain("typeof window.rfaRequestShow === 'function'")
        ->toContain('window.rfaRequestShow();')
        ->not->toContain("if (state.windows[id]) {\n        state.windows[id].show();\n    }");
});

test('renderer readiness window: fails open and cleans up its listener', function () {
    $content = rfaPatchRendererReadyWindow(stockWindowApi());

    expect($content)
        ->toContain('const rfaReadinessTimeoutMs = 5000;')
        ->toContain('rfaReadinessTimer = setTimeout(rfaPresent, rfaReadinessTimeoutMs)')
        ->toContain("removeListener('ipc-message', rfaRendererMessageListener)")
        ->toContain("rfaPresentationPhase = 'closed'");
});

test('renderer readiness window: never touches a destroyed webContents during cleanup', function () {
    $content = rfaPatchRendererReadyWindow(stockWindowApi());

    expect($content)
        ->toContain("if (rfaRendererMessageListener !== null && !window.isDestroyed()) {\n            window.webContents.removeListener('ipc-message', rfaRendererMessageListener);");
});

test('renderer readiness window: upgrades the previous frame-subscription revision', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/scripts/patch-nativephp.php');
    preg_match("/\\\$previousReplace = <<<'JS'\n(.*?)\nJS;/s", substr($source, strpos($source, 'function rfaPatchRendererReadyWindow')), $matches);
    $previous = $matches[1];
    $previousRevision = str_replace(
        [
            "    window.webContents.on('did-finish-load', () => {\n        if (state.noFocusOnRestart && window.isVisible()) {\n            return;\n        }\n        window.show();\n    });",
        ],
        [$previous],
        stockWindowApi(),
    );
    $previousRevision = (string) rfaPatchRendererReadyWindow($previousRevision);

    expect($previous)->toContain('beginFrameSubscription')
        ->and($previousRevision)->not->toContain('beginFrameSubscription')
        ->and($previousRevision)->toContain('!window.isDestroyed()')
        ->and(rfaPatchRendererReadyWindow($previousRevision))->toBe($previousRevision);
});

test('renderer readiness window: is idempotent', function () {
    $patched = rfaPatchRendererReadyWindow(stockWindowApi());

    expect(rfaPatchRendererReadyWindow($patched))->toBe($patched);
});

test('the vendored NativePHP main window waits for renderer readiness', function () {
    $windowPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/api/window.js';

    expect(file_get_contents($windowPath))
        ->toContain('Electron first paint and renderer stability')
        ->toContain('rfaPresentationPhase')
        ->toContain("channel !== 'rfa:renderer-ready'")
        ->toContain('setTimeout(rfaPresent, rfaReadinessTimeoutMs)')
        ->toContain('!window.isDestroyed()')
        ->not->toContain('beginFrameSubscription')
        ->not->toContain('rfaRendererReady');
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/api/window.js'), 'NativePHP desktop electron plugin not installed');

// -- Shape changes --

test('reports a shape change when the optimize block is missing', function () {
    expect(rfaPatchServerOptimize('const something = "no optimize block here";'))->toBeNull();
});

test('reports a shape change when only some edits can apply (partial patch)', function () {
    // A file where the optimize block exists but the opcache anchors do not —
    // e.g. a NativePHP bump reshaped the mkdir / pre-flight blocks. The optimize
    // edit alone must NOT count as a hit, or the opcache warm-up would silently
    // vanish; refusing here is what keeps the patch set from writing anything.
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
    expect(rfaPatchServerOptimize($optimizeOnly))->toBeNull();
});

// -- Fresh patch --

test('patches the optimize block', function () {
    $content = rfaPatchServerOptimize(stockServer());

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
    $content = rfaPatchServerOptimize(stockServer());

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
    expect(rfaPatchServerOptimize($reshaped))->toBeNull();
});

test('the cache step only runs behind the version gate after patching', function () {
    $content = rfaPatchServerOptimize(stockServer());

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
    $current = rfaPatchServerOptimize(stockServer());

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
    $content = rfaPatchServerOptimize(oldPatchedServer());

    expect($content)
        // The old config:cache warm-launch branch is gone…
        ->not->toContain('rfaCommand')
        ->not->toContain("'artisan', 'config:cache'")
        // …replaced by the current skip-entirely gate.
        ->toContain("existsSync(join(rfaCacheDir, 'config.php'))")
        ->toContain('if (rfaNeedsFullOptimize) {');

    // The upgrade is byte-identical to a fresh stock → current patch.
    expect($content)->toBe(rfaPatchServerOptimize(stockServer()));
});

test('upgrading a previously-patched file is idempotent', function () {
    $upgraded = rfaPatchServerOptimize(oldPatchedServer());

    expect(rfaPatchServerOptimize($upgraded))->toBe($upgraded);
});

// -- Idempotency --

test('a second run leaves an already-patched file untouched', function () {
    $patched = rfaPatchServerOptimize(stockServer());

    expect(rfaPatchServerOptimize($patched))->toBe($patched);
});

// -- Content integrity --

test('preserves the surrounding server code', function () {
    $content = rfaPatchServerOptimize(stockServer());

    expect($content)
        ->toContain("console.log('Starting Nightwatch server...')")
        ->toContain("console.log('Migrating database...')")
        // Version bookkeeping is retained, just gated behind a full optimize.
        ->toContain("store.set('optimized_version', app.getVersion())");
});

// -- Pre-flight cache (dist/index.js) --

test('pre-flight: reports a shape change when the load methods are missing', function () {
    expect(rfaPatchPreflightCache('const x = 1;'))->toBeNull();
});

test('pre-flight: caches native:config and native:php-ini per app version, fail-open', function () {
    $content = rfaPatchPreflightCache(stockIndex());

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

test('pre-flight: reports a shape change when only one method can be patched (partial)', function () {
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
    expect(rfaPatchPreflightCache($configOnly))->toBeNull();
});

test('pre-flight: is idempotent', function () {
    $first = rfaPatchPreflightCache(stockIndex());

    expect(rfaPatchPreflightCache($first))->toBe($first);
});

test('the vendored NativePHP main bootstrap carries the pre-flight cache', function () {
    $indexPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js';

    expect(file_get_contents($indexPath))
        ->toContain("'preflight_config_'")
        ->toContain("'preflight_phpini_'")
        ->toContain('import Store from "electron-store"; // [rfa preflight cache]');
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js'), 'NativePHP desktop electron plugin not installed');

// -- Splash window (dist/index.js) --

test('splash: reports a shape change when the bootstrap anchors are missing', function () {
    expect(rfaPatchSplashWindow('const x = 1;'))->toBeNull();
});

test('splash: opens an early window before PHP boots, hands off, and is fail-open', function () {
    $content = rfaPatchSplashWindow(stockIndexForSplash());

    expect($content)
        // BrowserWindow + nativeTheme are pulled into the electron import so the
        // splash can open and tint itself to the OS appearance.
        ->toContain('import { app, session, powerMonitor, BrowserWindow, nativeTheme } from "electron";')
        // The markup is embedded as a self-contained data URL — nothing to bundle.
        ->toContain('const RFA_SPLASH_HTML')
        ->toContain("loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(RFA_SPLASH_HTML))")
        // Fired the instant Electron is ready, before any PHP boot.
        ->toContain('this.rfaShowSplash(); // [rfa splash] instant feedback before PHP boots')
        // Seamless handoff: an implicit maximize cannot close the splash early.
        ->toContain("window.once('rfa:presented', () => this.rfaCloseSplash())")
        ->toContain('remains transparent')
        ->not->toContain("window.once('show', () => this.rfaCloseSplash())")
        ->not->toContain('`did-finish-load`')
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
    expect(rfaPatchSplashWindow(stockIndexForSplash()))
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
    $themed = rfaPatchSplashWindow(stockIndexForSplash());

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
    $content = rfaPatchSplashWindow(oldThemedSplashServer());

    expect($content)
        ->toContain('powerMonitor, BrowserWindow, nativeTheme')
        ->toContain('@media (prefers-color-scheme:dark)')
        ->toContain("backgroundColor: nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff'")
        ->not->toContain('#0d1117');

    // The upgrade is byte-identical to a fresh stock → themed patch.
    expect($content)->toBe(rfaPatchSplashWindow(stockIndexForSplash()));
});

test('splash: upgrading a previously dark-only splash is idempotent', function () {
    $upgraded = rfaPatchSplashWindow(oldThemedSplashServer());

    expect(rfaPatchSplashWindow($upgraded))->toBe($upgraded);
});

test('splash: preserves the existing browser-window-created listener', function () {
    // The patch adds its own handoff listener without clobbering NativePHP's.
    expect(rfaPatchSplashWindow(stockIndexForSplash()))
        ->toContain('optimizer.watchWindowShortcuts(window)');
});

test('splash: cleans up its window-created listener and closes on a torn-down window', function () {
    expect(rfaPatchSplashWindow(stockIndexForSplash()))
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
    $first = rfaPatchSplashWindow(stockIndexForSplash());

    expect(rfaPatchSplashWindow($first))->toBe($first);
});

test('splash: reports a shape change when only the import anchor is present (partial)', function () {
    // A NativePHP bump that reshaped the class but left the electron import. The
    // import edit alone must not count as a hit with the splash half-applied.
    $importOnly = 'import { app, session, powerMonitor } from "electron";';

    expect(rfaPatchSplashWindow($importOnly))->toBeNull();
});

test('the vendored NativePHP main bootstrap carries the splash window', function () {
    $indexPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js';

    expect(file_get_contents($indexPath))
        ->toContain('powerMonitor, BrowserWindow')
        ->toContain('const RFA_SPLASH_HTML')
        ->toContain('this.rfaShowSplash()');
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js'), 'NativePHP desktop electron plugin not installed');

// -- Resolved appearance (dist/index.js) --

test('appearance: reports a shape change when the splash methods are missing', function () {
    expect(rfaPatchResolvedAppearance('const x = 1;'))->toBeNull();
});

test('appearance: resolves the persisted Flux mode before creating the splash', function () {
    $content = rfaPatchResolvedAppearance(rfaPatchSplashWindow(stockIndexForSplash()));

    expect($content)
        ->toContain("name: 'rfa_appearance'")
        ->toContain("name: 'rfa_theme'")
        ->toContain("nativeTheme.themeSource = ['light', 'dark', 'system'].includes(rfaAppearance)")
        ->toContain("nativeTheme.themeSource = 'system'")
        ->toContain('backgroundColor: this.rfaBackgroundColor()')
        ->toContain('yield this.rfaResolveAppearance(); // [rfa appearance]');

    expect(strpos($content, 'yield this.rfaResolveAppearance()'))
        ->toBeLessThan(strpos($content, 'this.rfaShowSplash()'));
});

test('appearance: falls back to the legacy resolved theme on the first upgraded launch', function () {
    $content = rfaPatchResolvedAppearance(rfaPatchSplashWindow(stockIndexForSplash()));

    expect(strpos($content, "name: 'rfa_appearance'"))
        ->toBeLessThan(strpos($content, "name: 'rfa_theme'"))
        ->and($content)
        ->toContain('if (rfaAppearanceCookies.length > 0)')
        ->toContain('if (rfaLegacyThemeCookies.length > 0)');
});

test('appearance: upgrades the exact appearance-only cookie lookup', function () {
    $previous = str_replace(
        <<<'JS'
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
JS,
        <<<'JS'
                const rfaCookies = yield session.defaultSession.cookies.get({
                    url: 'http://127.0.0.1',
                    name: 'rfa_appearance',
                });
                const rfaAppearance = rfaCookies.length > 0 ? rfaCookies[0].value : 'system';
JS,
        rfaPatchResolvedAppearance(rfaPatchSplashWindow(stockIndexForSplash())),
    );

    expect(rfaPatchResolvedAppearance($previous))
        ->toBe(rfaPatchResolvedAppearance(rfaPatchSplashWindow(stockIndexForSplash())));
});

test('appearance: shares the exact RFA light and dark background tokens', function () {
    expect(rfaPatchResolvedAppearance(rfaPatchSplashWindow(stockIndexForSplash())))
        ->toContain("return nativeTheme.shouldUseDarkColors ? '#09090b' : '#ffffff'")
        ->not->toContain('#0d1117');
});

test('appearance: is idempotent', function () {
    $patched = rfaPatchResolvedAppearance(rfaPatchSplashWindow(stockIndexForSplash()));

    expect(rfaPatchResolvedAppearance($patched))->toBe($patched);
});

test('the vendored NativePHP main bootstrap resolves appearance before windows', function () {
    $indexPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js';

    expect(file_get_contents($indexPath))
        ->toContain('rfaResolveAppearance()')
        ->toContain("name: 'rfa_appearance'")
        ->toContain("name: 'rfa_theme'")
        ->toContain('backgroundColor: this.rfaBackgroundColor()');
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js'), 'NativePHP desktop electron plugin not installed');

// -- PHP extraction (php.js) --

test('PHP extraction: replaces the complete stock installer block', function () {
    $content = rfaPatchPhpExtraction(stockPhpInstaller());

    expect($content)
        ->toContain('[rfa php extraction]')
        ->toContain('removeSync(binaryDestDir);')
        ->toContain('ensureDirSync(binaryDestDir);')
        ->toContain('fileName !== platform.phpBinary')
        ->toContain('fs.chmodSync(binaryPath, 0o755);')
        ->toContain('process.exitCode = 1;')
        ->not->toContain('unzip.open(binarySrcDir');
});

test('PHP extraction: upgrades the exact current-head partial block', function () {
    $current = rfaPatchPhpExtraction(stockPhpInstaller());
    $destinationPreparation = <<<'JS'
        console.log('Unzipping PHP binary from ' + binarySrcDir + ' to ' + binaryDestDir);
        removeSync(binaryDestDir);
        ensureDirSync(binaryDestDir);

JS;
    $previous = str_replace($destinationPreparation, '', $current);

    expect(rfaPatchPhpExtraction($previous))->toBe($current);
});

test('PHP extraction: rejects incomplete patched blocks', function (string $missingFragment) {
    $current = rfaPatchPhpExtraction(stockPhpInstaller());
    $partial = str_replace($missingFragment, '', $current);

    expect($partial)->not->toBe($current)
        ->and(rfaPatchPhpExtraction($partial))->toBeNull();
})->with([
    'destination cleanup' => '        removeSync(binaryDestDir);'.PHP_EOL,
    'destination creation' => '        ensureDirSync(binaryDestDir);'.PHP_EOL,
    'archive filename validation' => ' || fileName !== platform.phpBinary',
    'executable mode' => '        fs.chmodSync(binaryPath, 0o755);'.PHP_EOL,
    'failure status' => '        process.exitCode = 1;'.PHP_EOL,
]);

test('PHP extraction: leaves the complete patched block unchanged', function () {
    $patched = rfaPatchPhpExtraction(stockPhpInstaller());

    expect(rfaPatchPhpExtraction($patched))->toBe($patched);
});

// -- PHP build wait (electron-builder.mjs) --

test('PHP build wait: waits for extraction without changing permissions', function () {
    $content = rfaPatchPhpBuildWait(stockElectronBuilder());

    expect($content)
        ->toContain('[rfa php build wait]')
        ->toContain("execFileSync(process.execPath, ['php.js'")
        ->not->toContain('[rfa php build permission]')
        ->not->toContain('[rfa php build path]')
        ->not->toContain('chmodSync(');
});

test('PHP build wait: upgrades the exact permission-owning block', function () {
    $previous = str_replace(
        "import { exec } from 'child_process';",
        "import { execFileSync } from 'child_process'; // [rfa php build wait]\nimport { chmodSync } from 'fs'; // [rfa php build permission]\nimport { join } from 'path'; // [rfa php build path]",
        stockElectronBuilder(),
    );
    $previous = str_replace(
        '        exec(`node php.js --${targetOs} --${arch}`);',
        <<<'JS'
        execFileSync(process.execPath, ['php.js', `--${targetOs}`, `--${arch}`], { stdio: 'inherit' });
        if (targetOs !== 'win') {
            chmodSync(join(process.env.NATIVEPHP_BUILD_PATH, 'php', 'php'), 0o755);
        }
JS,
        $previous,
    );

    expect(rfaPatchPhpBuildWait($previous))->toBe(rfaPatchPhpBuildWait(stockElectronBuilder()));
});

test('PHP build wait: leaves the complete patched block unchanged', function () {
    $patched = rfaPatchPhpBuildWait(stockElectronBuilder());

    expect(rfaPatchPhpBuildWait($patched))->toBe($patched);
});

// ===== server/php.js: forked built-in server workers =====

test('server workers: reports a shape change when the server spawn is missing', function () {
    expect(rfaPatchServerWorkers('const something = "no spawn here";'))->toBeNull();
});

test('server workers: forks the built-in server and leaves artisan calls alone', function () {
    $content = rfaPatchServerWorkers(stockServer());

    expect($content)
        ->toContain("env: Object.assign({}, env, { PHP_CLI_SERVER_WORKERS: '4' })")
        ->toContain('[rfa php workers]')
        ->and(substr_count((string) $content, 'PHP_CLI_SERVER_WORKERS'))->toBe(1)
        ->and($content)->toContain("callPhpSync(['artisan', 'optimize'], phpOptions, phpIniSettings)");
});

test('server workers: is idempotent', function () {
    $patched = rfaPatchServerWorkers(stockServer());

    expect(rfaPatchServerWorkers($patched))->toBe($patched);
});

// ===== index.js: PHP server before Electron readiness + opcache warm-up =====

function indexReadyForEarlyPhpBoot(): string
{
    $index = stockIndexForSplash()."\n".stockIndex();

    foreach ([rfaPatchPreflightCache(...), rfaPatchSplashWindow(...), rfaPatchResolvedAppearance(...)] as $patch) {
        $index = (string) $patch($index);
    }

    return $index;
}

test('early php boot: reports a shape change when the bootstrap sequence is missing', function () {
    expect(rfaPatchEarlyPhpBoot(stockIndex()))->toBeNull();
});

test('early php boot: refuses a bootstrap that the splash and appearance patches have not shaped yet', function () {
    // Applied out of order, the whenReady block still has the stock shape and
    // the reorder would drop the splash and appearance lines. Refusing keeps
    // the patch set from writing anything.
    expect(rfaPatchEarlyPhpBoot(stockIndexForSplash()."\n".stockIndex()))->toBeNull();
});

test('early php boot: spawns PHP first, warms it, then waits for Electron and the splash', function () {
    $content = (string) rfaPatchEarlyPhpBoot(indexReadyForEarlyPhpBoot());

    expect($content)
        ->toContain('import axios from "axios"; // [rfa early php]')
        ->toContain('const rfaPhpBoot = this.startPhpApp().then(() => this.rfaWarmPhp()).catch((rfaError) => rfaError);')
        ->toContain('const rfaPhpFailure = yield rfaPhpBoot;')
        ->toContain('throw rfaPhpFailure;')
        ->toContain('rfaWarmPhp() {')
        ->toContain('/_rfa/warm`')
        ->toContain("headers: { 'X-NativePHP-Secret': state.randomSecret }")
        ->toContain('timeout: 4000')
        ->toContain('const rfaConfig = this.loadConfig();')
        ->toContain('const config = yield rfaConfig;')
        ->toContain('yield this.rfaResolveAppearance(); // [rfa appearance] resolve before either window exists')
        ->toContain('this.rfaShowSplash(); // [rfa splash] instant feedback before PHP boots')
        ->toContain('yield notifyLaravel("booted");')
        ->and(substr_count($content, 'rfaWarmPhp() {'))->toBe(1)
        ->and(strpos($content, 'const rfaPhpBoot'))->toBeLessThan(strpos($content, 'yield app.whenReady();'))
        ->and(strpos($content, 'this.rfaShowSplash();'))->toBeLessThan(strpos($content, 'const rfaPhpFailure'))
        ->and(strpos($content, 'const rfaPhpFailure'))->toBeLessThan(strpos($content, 'this.startScheduler();'));
});

test('early php boot: leaves the splash and appearance patches idempotent', function () {
    $content = (string) rfaPatchEarlyPhpBoot(indexReadyForEarlyPhpBoot());

    expect(rfaPatchSplashWindow($content))->toBe($content)
        ->and(rfaPatchResolvedAppearance($content))->toBe($content)
        ->and(rfaPatchPreflightCache($content))->toBe($content);
});

test('early php boot: is idempotent', function () {
    $patched = rfaPatchEarlyPhpBoot(indexReadyForEarlyPhpBoot());

    expect(rfaPatchEarlyPhpBoot($patched))->toBe($patched);
});

test('the vendored NativePHP main bootstrap starts PHP before Electron is ready', function () {
    $indexPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js';

    expect(file_get_contents($indexPath))
        ->toContain('[rfa early php]')
        ->toContain('const rfaPhpBoot = this.startPhpApp().then(() => this.rfaWarmPhp())')
        ->toContain('/_rfa/warm`');
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
        ->toContain("command.unshift('-d', 'opcache.enable_cli=1'")
        ->toContain("PHP_CLI_SERVER_WORKERS: '4'");
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js'), 'NativePHP desktop electron plugin not installed');

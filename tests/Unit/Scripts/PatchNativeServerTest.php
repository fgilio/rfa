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

<?php

require_once dirname(__DIR__, 3).'/scripts/patch-native-server.php';

// -- Fixture: stock NativePHP server bootstrap (unpatched optimize block) --

function stockServer(): string
{
    return <<<'JS'
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

// -- Fresh patch --

test('patches the optimize block and returns patched', function () {
    $path = tempServer(stockServer());

    expect(patchNativeServerOptimize($path))->toBe('patched');

    $content = file_get_contents($path);

    expect($content)
        ->toContain('[rfa patch]')
        ->toContain('const rfaNeedsFullOptimize')
        ->toContain("rfaNeedsFullOptimize ? 'optimize' : 'config:cache'")
        ->toContain("existsSync(join(bootstrapCache, 'routes-v7.php'))")
        ->toContain("existsSync(join(bootstrapCache, 'events.php'))");
});

test('the unconditional every-launch optimize call is gone after patching', function () {
    $path = tempServer(stockServer());

    patchNativeServerOptimize($path);
    $content = file_get_contents($path);

    // The stock build always ran the full `optimize`; after patching the only
    // unconditional command is the version-gated choice, never a bare optimize.
    expect($content)->not->toContain("callPhpSync(['artisan', 'optimize']");
    expect($content)->toContain("callPhpSync(['artisan', rfaCommand]");
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

// -- Applied to the real vendored file --

test('the vendored NativePHP server carries the optimize patch', function () {
    $serverPath = dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js';

    // post-autoload-dump applies this patch on install. Assert the marker is
    // present (non-mutating) so a NativePHP bump that reshapes the optimize
    // block — making the patch silently no-op (block_not_found) and shipping an
    // every-launch optimize again — fails loudly here instead.
    expect(file_get_contents($serverPath))->toContain('rfaNeedsFullOptimize');
})->skip(fn () => ! file_exists(dirname(__DIR__, 3).'/vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js'), 'NativePHP desktop electron plugin not installed');

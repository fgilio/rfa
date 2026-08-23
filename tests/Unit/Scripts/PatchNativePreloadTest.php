<?php

require_once dirname(__DIR__, 3).'/scripts/patch-nativephp.php';
require_once dirname(__DIR__, 2).'/Helpers/native-php-dist-fixtures.php';

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

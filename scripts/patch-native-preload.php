<?php

/**
 * Patch NativePHP's Electron preload to expose:
 *   - webUtils.getPathForFile (drag-and-drop; Electron 38+ removed File.path)
 *   - window.rfaLifecycle.{forceQuit,restart} (renderer escape hatch when Laravel is dead)
 *
 * Bumping the sentinel version is a deliberate signal that the patch shape
 * changed and any pre-existing patched file needs re-patching.
 */

require_once __DIR__.'/lib/native-patch-helpers.php';

const PRELOAD_SENTINEL = '[rfa patch] preload v1';

/**
 * @return 'patched'|'already_patched'|'import_not_found'|'not_found'|'write_failed'
 */
function patchNativePreload(string $preloadPath): string
{
    if (! file_exists($preloadPath)) {
        return 'not_found';
    }

    $content = file_get_contents($preloadPath);

    if (str_contains($content, PRELOAD_SENTINEL)) {
        return 'already_patched';
    }

    $original = $content;
    $content = str_replace(
        'import { ipcRenderer, contextBridge } from "electron";',
        'import { ipcRenderer, contextBridge, webUtils } from "electron";',
        $content
    );
    if ($content === $original) {
        return 'import_not_found';
    }

    $sentinel = PRELOAD_SENTINEL;
    $content .= <<<JS

// {$sentinel}
contextBridge.exposeInMainWorld('nativeGetFilePath', (file) => webUtils.getPathForFile(file));

// Strict-capability bridge: no secret, no HTTP. Main process patch in
// scripts/patch-native-electron-main.php registers the matching ipcMain.on handlers.
contextBridge.exposeInMainWorld('rfaLifecycle', {
    forceQuit: () => ipcRenderer.send('rfa:force-quit'),
    restart: () => ipcRenderer.send('rfa:restart'),
});
JS;

    return nativePatchWriteAtomic($preloadPath, $content."\n") ? 'patched' : 'write_failed';
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $preloadPath = __DIR__.'/../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/preload/index.mjs';

    $result = patchNativePreload($preloadPath);

    match ($result) {
        'patched' => print "  NativePHP preload patched (preload v1: nativeGetFilePath, rfaLifecycle).\n",
        'already_patched' => print "  NativePHP preload already patched.\n",
        'not_found' => nativePatchExitWithError("NativePHP preload not found at {$preloadPath}. Vendor missing or path changed."),
        'import_not_found' => nativePatchExitWithError('NativePHP preload import line not found. Upstream shape may have changed; this script needs updating.'),
        'write_failed' => nativePatchExitWithError("Failed to write patched preload to {$preloadPath}."),
    };
}

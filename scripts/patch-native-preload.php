<?php

/**
 * Patch NativePHP's Electron preload to expose webUtils.getPathForFile().
 *
 * Electron 38+ removed File.path from the renderer. The replacement,
 * webUtils.getPathForFile(), is only available in the preload context.
 * NativePHP's preload doesn't expose it, so we patch the compiled JS
 * to bridge it via contextBridge.
 *
 * Runs automatically via composer post-autoload-dump.
 */

/**
 * @return 'patched'|'already_patched'|'import_not_found'|'not_found'
 */
function patchNativePreload(string $preloadPath): string
{
    if (! file_exists($preloadPath)) {
        return 'not_found';
    }

    $content = file_get_contents($preloadPath);

    if (str_contains($content, '[rfa patch]')) {
        return 'already_patched';
    }

    // Add webUtils to the electron import
    $original = $content;
    $content = str_replace(
        'import { ipcRenderer, contextBridge } from "electron";',
        'import { ipcRenderer, contextBridge, webUtils } from "electron";',
        $content
    );

    if ($content === $original) {
        return 'import_not_found';
    }

    // Expose getPathForFile to the renderer via contextBridge
    $content .= <<<'JS'

// [rfa patch] Expose webUtils.getPathForFile for drag-and-drop support.
// File objects pass through contextBridge via structured cloning.
contextBridge.exposeInMainWorld('nativeGetFilePath', (file) => webUtils.getPathForFile(file));
JS;

    file_put_contents($preloadPath, $content."\n");

    return 'patched';
}

// Run when executed directly (not when required by tests)
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $preloadPath = __DIR__.'/../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/preload/index.mjs';

    $result = patchNativePreload($preloadPath);

    match ($result) {
        'patched' => print "  NativePHP preload patched: webUtils.getPathForFile exposed.\n",
        'already_patched' => print "  NativePHP preload already patched (webUtils).\n",
        'import_not_found' => fwrite(STDERR, "  WARNING: NativePHP preload import line not found. Patch skipped.\n"),
        'not_found' => null,
    };
}

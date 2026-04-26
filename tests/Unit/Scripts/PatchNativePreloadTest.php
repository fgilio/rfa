<?php

require_once dirname(__DIR__, 3).'/scripts/patch-native-preload.php';

// -- Fixture: stock NativePHP preload (unpatched) --

function stockPreload(): string
{
    return <<<'JS'
import remote from "@electron/remote";
import { ipcRenderer, contextBridge } from "electron";
const Native = {
    on: (event, callback) => {
        ipcRenderer.on('native-event', (_, data) => {
            event = event.replace(/^(\\)+/, '');
            data.event = data.event.replace(/^(\\)+/, '');
            if (event === data.event) {
                return callback(data.payload, event);
            }
        });
    },
    contextMenu: (template) => {
        let menu = remote.Menu.buildFromTemplate(template);
        menu.popup({ window: remote.getCurrentWindow() });
    }
};
contextBridge.exposeInMainWorld('Native', Native);
JS;
}

function tempPreload(?string $content = null): string
{
    $dir = sys_get_temp_dir().'/rfa_test_preload_'.getmypid().'_'.uniqid('', true);
    mkdir($dir, 0755, true);
    $path = $dir.'/index.mjs';

    if ($content !== null) {
        file_put_contents($path, $content);
    }

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/rfa_test_preload_'.getmypid().'_*', GLOB_ONLYDIR) as $dir) {
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
});

// -- Missing file --

test('returns not_found when preload file does not exist', function () {
    $path = tempPreload(); // dir exists but file does not

    expect(patchNativePreload($path))->toBe('not_found');
});

// -- Import not found --

test('returns import_not_found when electron import line is missing', function () {
    $path = tempPreload('const something = "no electron import here";');

    expect(patchNativePreload($path))->toBe('import_not_found');

    // File should be unchanged
    expect(file_get_contents($path))->toBe('const something = "no electron import here";');
});

// -- Fresh patch --

test('patches unpatched preload and returns patched', function () {
    $path = tempPreload(stockPreload());

    expect(patchNativePreload($path))->toBe('patched');

    $content = file_get_contents($path);

    expect($content)
        ->toContain('import { ipcRenderer, contextBridge, webUtils } from "electron";')
        ->toContain("contextBridge.exposeInMainWorld('nativeGetFilePath'")
        ->toContain('webUtils.getPathForFile(file)')
        ->toContain('[rfa patch]');
});

test('replaces the original import without duplicating it', function () {
    $path = tempPreload(stockPreload());

    patchNativePreload($path);
    $content = file_get_contents($path);

    expect(substr_count($content, 'from "electron"'))->toBe(1);
});

// -- Idempotency --

test('returns already_patched on second run', function () {
    $path = tempPreload(stockPreload());

    patchNativePreload($path);

    expect(patchNativePreload($path))->toBe('already_patched');
});

test('does not duplicate patch on repeated runs', function () {
    $path = tempPreload(stockPreload());

    patchNativePreload($path);
    $contentAfterFirst = file_get_contents($path);

    patchNativePreload($path);
    $contentAfterSecond = file_get_contents($path);

    expect($contentAfterSecond)->toBe($contentAfterFirst);
});

// -- Content integrity --

test('preserves existing preload code', function () {
    $path = tempPreload(stockPreload());

    patchNativePreload($path);
    $content = file_get_contents($path);

    expect($content)
        ->toContain('import remote from "@electron/remote"')
        ->toContain("contextBridge.exposeInMainWorld('Native', Native)")
        ->toContain('contextMenu: (template)');
});

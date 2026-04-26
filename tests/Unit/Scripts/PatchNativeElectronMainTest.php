<?php

require_once dirname(__DIR__, 3).'/scripts/patch-native-electron-main.php';

// -- Fixtures --

function stockElectronMain(): string
{
    // Reproduces the relevant slices of the bundled main process: import line
    // (matches the real vendor's import shape, which already destructures
    // ipcMain), serveApp's phpServer block (close handler is the silent
    // upstream version), and the addEventListeners method's before-quit handler.
    return <<<'JS'
import { session, clipboard, nativeImage, dialog, app, screen, systemPreferences, safeStorage, BrowserWindow, nativeTheme, globalShortcut, Notification, shell, Menu, Tray, powerMonitor, utilityProcess, ipcMain } from "electron";
import { enable, initialize } from "@electron/remote/main/index.js";
import Store from "electron-store";

function serveApp(secret, apiPort, phpIniSettings) {
  return new Promise((resolve2, reject) => {
    const phpPort = 8100;
    const phpServer = callPhp(["-S", `127.0.0.1:${phpPort}`, serverPath], {
      cwd,
      env
    }, phpIniSettings);
    const portRegex = /Development Server \(.*:([0-9]+)\) started/gm;
    phpServer.stdout.on("data", (data) => {});
    phpServer.stderr.on("data", (data) => {});
    phpServer.on("error", (error) => { reject(error); });
    phpServer.on("close", (code) => {
      console.log(`PHP server exited with code ${code}`);
    });
  });
}

class NativePHP {
  addEventListeners(app2) {
    app2.on("open-url", (event, url2) => {});
    app2.on("window-all-closed", () => {
      if (process.platform !== "darwin") {
        app2.quit();
      }
    });
    app2.on("before-quit", () => {
      if (this.schedulerInterval) {
        clearInterval(this.schedulerInterval);
      }
      stopAllProcesses();
      this.killChildProcesses();
    });
  }
}
JS;
}

function legacyV1PatchedElectronMain(): string
{
    // Reproduces what the v1 (Layer-2-only) patch script left behind: lifecycle
    // handlers inserted above before-quit with the legacy
    // '[rfa patch] electron-main v1' sentinel.
    $insertion = <<<'JS'
    // [rfa patch] electron-main v1
    // rfaLifecycle IPC handlers inserted by the v1 script
    ipcMain.on('rfa:force-quit', () => {
      app2.quit();
    });
    ipcMain.on('rfa:restart', () => {
      app2.relaunch();
      app2.quit();
    });
    app2.on("before-quit", () => {
JS;

    return str_replace(
        '    app2.on("before-quit", () => {',
        $insertion,
        stockElectronMain()
    );
}

function tempElectronMain(?string $content = null): string
{
    $dir = sys_get_temp_dir().'/rfa_test_electron_main_'.uniqid();
    mkdir($dir, 0755, true);
    $path = $dir.'/index.js';

    if ($content !== null) {
        file_put_contents($path, $content);
    }

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/rfa_test_electron_main_*', GLOB_ONLYDIR) as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($dir.'/.*') ?: [] as $file) {
            if (! is_dir($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }
});

// -- Missing file --

test('returns not_found when electron-main file does not exist', function () {
    $path = tempElectronMain();

    expect(patchNativeElectronMain($path))->toBe('not_found');
});

// -- Anchor missing --
// Each fixture removes a different anchor the patch script depends on; all
// should yield 'anchor_not_found' so CI catches an upstream shape change.

test('returns anchor_not_found when an upstream anchor is missing', function (Closure $fixture) {
    $path = tempElectronMain($fixture());

    expect(patchNativeElectronMain($path))->toBe('anchor_not_found');
})->with([
    'ipcMain not destructured from electron' => [
        // Precondition: real vendor imports ipcMain. If a future upstream
        // drops it, we need to fail loudly because the lifecycle handlers
        // reference it.
        fn () => <<<'JS'
import { app, BrowserWindow } from "electron";
class NativePHP {
  addEventListeners(app2) {
    app2.on("before-quit", () => {});
  }
}
JS,
    ],
    'before-quit handler missing' => [
        fn () => <<<'JS'
import { app } from "electron";
class NativePHP {
  addEventListeners(app2) {
    app2.on("ready", () => {});
  }
}
JS,
    ],
    'phpServer spawn missing' => [
        fn () => <<<'JS'
import { app, ipcMain } from "electron";
class NativePHP {
  addEventListeners(app2) {
    app2.on("before-quit", () => {});
  }
}
JS,
    ],
    'close handler shape missing' => [
        fn () => preg_replace(
            '/phpServer\.on\("close".*?\}\);/s',
            '',
            stockElectronMain()
        ),
    ],
]);

// -- Fresh patch (full v2) --

test('patches unpatched electron-main and returns patched', function () {
    $path = tempElectronMain(stockElectronMain());

    expect(patchNativeElectronMain($path))->toBe('patched');

    $content = file_get_contents($path);

    expect($content)
        ->toContain('[rfa patch] electron-main-lifecycle v1')
        ->toContain("ipcMain.on('rfa:force-quit'")
        ->toContain("ipcMain.on('rfa:restart'")
        ->toContain('[rfa patch] electron-main-phpserver v1')
        ->toContain('state.phpServer = phpServer;')
        ->toContain('[rfa patch] electron-main-restart v1')
        ->toContain('__rfaRestartHandler')
        ->toContain('[rfa patch] electron-main-deadline v1')
        ->toContain('state.shuttingDown = true')
        ->toContain('app2.exit(0)');
});

test('preserves existing before-quit body', function () {
    $path = tempElectronMain(stockElectronMain());

    patchNativeElectronMain($path);
    $content = file_get_contents($path);

    expect($content)
        ->toContain('clearInterval(this.schedulerInterval)')
        ->toContain('stopAllProcesses()')
        ->toContain('this.killChildProcesses()');
});

test('removes the silent upstream close handler', function () {
    $path = tempElectronMain(stockElectronMain());

    patchNativeElectronMain($path);
    $content = file_get_contents($path);

    // The original close-handler body is "console.log(`PHP server exited with code ${code}`);"
    // After patching, that exact line should be gone (replaced by our handler).
    expect($content)
        ->not->toContain('console.log(`PHP server exited with code ${code}`)');
});

// -- Idempotency --

test('returns already_patched on second run', function () {
    $path = tempElectronMain(stockElectronMain());

    patchNativeElectronMain($path);

    expect(patchNativeElectronMain($path))->toBe('already_patched');
});

test('does not duplicate any insertion on repeated runs', function () {
    $path = tempElectronMain(stockElectronMain());

    patchNativeElectronMain($path);
    $first = file_get_contents($path);

    patchNativeElectronMain($path);
    $second = file_get_contents($path);

    expect($second)->toBe($first);
    expect(substr_count($second, "ipcMain.on('rfa:force-quit'"))->toBe(1);
    // const definition + recursive newServer.on('close', ...) + phpServer.on('close', ...)
    expect(substr_count($second, '__rfaRestartHandler'))->toBe(3);
    expect(substr_count($second, 'state.shuttingDown = true'))->toBe(1);
});

// -- v1 → v2 migration --

test('migrates v1 (Layer 2-only) patched file to v2 cleanly', function () {
    $path = tempElectronMain(legacyV1PatchedElectronMain());

    expect(patchNativeElectronMain($path))->toBe('patched');

    $content = file_get_contents($path);

    // New per-op sentinels present
    expect($content)
        ->toContain('[rfa patch] electron-main-lifecycle v1')
        ->toContain('[rfa patch] electron-main-phpserver v1')
        ->toContain('[rfa patch] electron-main-restart v1')
        ->toContain('[rfa patch] electron-main-deadline v1');

    // Legacy global sentinel was swapped out
    expect($content)->not->toContain('[rfa patch] electron-main v1');

    // No duplicate handlers
    expect(substr_count($content, "ipcMain.on('rfa:force-quit'"))->toBe(1);
});

// -- Atomic writes --

test('does not leave a .tmp sibling after a successful patch', function () {
    $path = tempElectronMain(stockElectronMain());

    patchNativeElectronMain($path);

    expect(file_exists($path.'.tmp'))->toBeFalse();
});

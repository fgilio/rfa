<?php

/**
 * Patch NativePHP's Electron main process for backend recovery:
 *   - Lifecycle IPC (`rfa:force-quit`, `rfa:restart`) for the renderer overlay.
 *   - state.phpServer registration so before-quit can SIGKILL it directly.
 *   - Self-rearming respawn on phpServer 'close'.
 *   - 3 s before-quit deadline (the upstream `killSync(pid, 'SIGTERM', true)`
 *     tree-walk hangs on uninterruptible-sleep children — observed wedge cause).
 *
 * Precondition: bundled main.js destructures `ipcMain` from "electron".
 *
 * Each op carries its own per-version sentinel so cross-version pulls
 * (post-autoload-dump runs without vendor reset) can migrate selectively.
 */

require_once __DIR__.'/lib/native-patch-helpers.php';

const PATCH_LIFECYCLE = '[rfa patch] electron-main-lifecycle v1';
const PATCH_PHPSERVER_REG = '[rfa patch] electron-main-phpserver v1';
const PATCH_RESTART = '[rfa patch] electron-main-restart v1';
const PATCH_DEADLINE = '[rfa patch] electron-main-deadline v1';

// Pre-v1 unified sentinel from the Layer 2-only script. Treated as already
// applying the lifecycle op; gets swapped to PATCH_LIFECYCLE in place.
const PATCH_LEGACY_LIFECYCLE = '[rfa patch] electron-main v1';

/**
 * @return 'patched'|'already_patched'|'anchor_not_found'|'not_found'|'write_failed'
 */
function patchNativeElectronMain(string $mainPath): string
{
    if (! file_exists($mainPath)) {
        return 'not_found';
    }

    $original = file_get_contents($mainPath);
    $content = $original;

    if (preg_match('/^import\s*\{[^}]*\bipcMain\b[^}]*\}\s*from\s*"electron";/m', $content) !== 1) {
        return 'anchor_not_found';
    }

    [$content, $lifecycleOk] = applyLifecyclePatch($content);
    if (! $lifecycleOk) {
        return 'anchor_not_found';
    }

    [$content, $regOk] = applyPhpServerRegistration($content);
    if (! $regOk) {
        return 'anchor_not_found';
    }

    [$content, $restartOk] = applyAutoRestartPatch($content);
    if (! $restartOk) {
        return 'anchor_not_found';
    }

    [$content, $deadlineOk] = applyQuitDeadlinePatch($content);
    if (! $deadlineOk) {
        return 'anchor_not_found';
    }

    if ($content === $original) {
        return 'already_patched';
    }

    return nativePatchWriteAtomic($mainPath, $content) ? 'patched' : 'write_failed';
}

/**
 * @return array{0: string, 1: bool}
 */
function applyLifecyclePatch(string $content): array
{
    $sentinel = PATCH_LIFECYCLE;
    $legacy = PATCH_LEGACY_LIFECYCLE;

    if (str_contains($content, $sentinel)) {
        return [$content, true];
    }

    if (str_contains($content, $legacy)) {
        $patched = str_replace($legacy, $sentinel, $content);

        return [$patched, $patched !== $content];
    }

    $insertion = <<<JS
    // {$sentinel}
    ipcMain.on('rfa:force-quit', () => {
      app2.quit();
    });
    ipcMain.on('rfa:restart', () => {
      app2.relaunch();
      app2.quit();
    });
JS;

    $pattern = '/(^[ \t]*app2\.on\("before-quit",\s*\(\)\s*=>\s*\{)/m';
    if (preg_match($pattern, $content) !== 1) {
        return [$content, false];
    }

    $patched = preg_replace($pattern, $insertion."\n$1", $content, 1);

    return [$patched ?? $content, $patched !== null && $patched !== $content];
}

/**
 * @return array{0: string, 1: bool}
 */
function applyPhpServerRegistration(string $content): array
{
    $sentinel = PATCH_PHPSERVER_REG;

    if (str_contains($content, $sentinel)) {
        return [$content, true];
    }

    $pattern = '/(const phpServer = callPhp\(\["-S",[^;]*?\}, phpIniSettings\);)/s';
    if (preg_match($pattern, $content) !== 1) {
        return [$content, false];
    }

    $insertion = "$1\n    // {$sentinel}\n    state.phpServer = phpServer;";
    $patched = preg_replace($pattern, $insertion, $content, 1);

    return [$patched ?? $content, $patched !== null && $patched !== $content];
}

/**
 * @return array{0: string, 1: bool}
 */
function applyAutoRestartPatch(string $content): array
{
    $sentinel = PATCH_RESTART;

    if (str_contains($content, $sentinel)) {
        return [$content, true];
    }

    $anchor = <<<'JS'
    phpServer.on("close", (code) => {
      console.log(`PHP server exited with code ${code}`);
    });
JS;

    if (! str_contains($content, $anchor)) {
        return [$content, false];
    }

    // Reuses the captured `phpPort` so the renderer's APP_URL stays valid;
    // calling getPhpPort() again would orphan the renderer onto a dead port.
    // No backoff/give-up: crash loops are theoretical, and the renderer
    // overlay surfaces give-up after 30s of failed health probes.
    $replacement = <<<JS
    // {$sentinel}
    const __rfaRestartHandler = (code, signal) => {
      if (state.shuttingDown) return;
      console.error(`[rfa] PHP server exited code=\${code} signal=\${signal} — respawning on port \${phpPort}`);
      const newServer = callPhp(["-S", `127.0.0.1:\${phpPort}`, serverPath], { cwd, env }, phpIniSettings);
      state.phpServer = newServer;
      newServer.on("error", (err) => {
        console.error('[rfa] PHP respawn error:', err);
      });
      newServer.on("close", __rfaRestartHandler);
    };
    phpServer.on("close", __rfaRestartHandler);
JS;

    $patched = str_replace($anchor, $replacement, $content);

    return [$patched, $patched !== $content];
}

/**
 * @return array{0: string, 1: bool}
 */
function applyQuitDeadlinePatch(string $content): array
{
    $sentinel = PATCH_DEADLINE;

    if (str_contains($content, $sentinel)) {
        return [$content, true];
    }

    $pattern = '/(app2\.on\("before-quit",\s*\(\)\s*=>\s*\{)/';
    if (preg_match($pattern, $content) !== 1) {
        return [$content, false];
    }

    $insertion = <<<JS
\$1
      // {$sentinel}
      state.shuttingDown = true;
      // SIGKILL the dev server up front. The upstream cleanup uses
      // killSync(pid, 'SIGTERM', true) which spawns pidtree/ps walks that
      // hang on uninterruptible-sleep children — observed wedge cause.
      try {
        if (state.phpServer && !state.phpServer.killed) {
          state.phpServer.kill('SIGKILL');
        }
      } catch (e) {
        console.error('[rfa] failed to SIGKILL php-server:', e);
      }
      setTimeout(() => {
        console.error('[rfa] before-quit hit 3s deadline, forcing app.exit(0)');
        app2.exit(0);
      }, 3000);
JS;

    $patched = preg_replace($pattern, $insertion, $content, 1);

    return [$patched ?? $content, $patched !== null && $patched !== $content];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $mainPath = __DIR__.'/../vendor/nativephp/desktop/resources/electron/out/main/index.js';

    $result = patchNativeElectronMain($mainPath);

    match ($result) {
        'patched' => print "  NativePHP electron-main patched (lifecycle + phpserver-reg + auto-restart + quit-deadline).\n",
        'already_patched' => print "  NativePHP electron-main already patched.\n",
        // out/main/index.js is an electron-vite build artifact, not shipped in
        // the package dist. On a fresh `composer install` (CI, post-clone) it
        // legitimately doesn't exist yet — skip without failing so non-build
        // jobs (lint, types, tests) stay green. The patch reapplies the next
        // time composer's autoload hook fires after `electron-vite build` has
        // produced the file.
        'not_found' => print "  NativePHP electron-main not built yet at {$mainPath} — skipping patch.\n",
        'anchor_not_found' => nativePatchExitWithError('NativePHP electron-main anchor missing. Upstream shape may have changed; this script needs updating.'),
        'write_failed' => nativePatchExitWithError("Failed to write patched electron-main to {$mainPath}."),
    };
}

# Upstream issue brief: PHP dev server can die silently in NativePHP for Electron

A self-contained briefing for filing the upstream issue/PR. Open the matching repo, work through the checklist, copy what you need.

## Checklist for tomorrow

- [ ] Confirm the upstream repo (see "Where to file" below).
- [ ] Clone it; locate the `.ts`/`.js` source for the PHP server lifecycle (the `out/main/index.js` we read locally is compiled).
- [ ] Open issue with the title and body below.
- [ ] If you have time, follow up with a PR. Suggested split into two commits at the end of this doc.
- [ ] Once filed, paste the issue/PR URL into `.claude/plans/this-can-never-happen-misty-rivest.md` under "Pre-work" and into `scripts/patch-native-electron-main.php` once that lands.

## Where to file

Most likely: **`NativePHP/electron-plugin`** — the source for the bundled `electron-plugin/dist/...` JS that ships inside `nativephp/desktop`.

Confirm before opening the issue:

- Read `vendor/nativephp/desktop/composer.json` and `vendor/nativephp/desktop/package.json` for the upstream `electron-plugin` source URL.
- The bundled path locally is `vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/php.js` — the `electron-plugin/` segment is a hint that it's a separately-versioned package.

Fallback if `electron-plugin` is internal-only: file under **`NativePHP/desktop`** with the same content.

## Issue title

> PHP dev server can die silently — close handler only logs to discarded stdout, no restart, no surfacing

## Issue body

### Summary

When the bundled `php -S 127.0.0.1:<port>` process exits unexpectedly, the Electron main process logs a single line to stdout and does nothing else. For Finder-launched packaged apps, stdout is discarded, so the user sees a frozen UI with no diagnostic. The renderer keeps issuing Livewire `update` requests against the dead port and gets `ERR_CONNECTION_REFUSED` indefinitely. The only recovery is force-quit + relaunch.

This is a single-point-of-failure for every NativePHP-for-Electron app.

### Where the bug lives

In the compiled bundle (locally readable at `vendor/nativephp/desktop/resources/electron/out/main/index.js:2258-2260`):

```js
phpServer.on("close", (code) => {
  console.log(`PHP server exited with code ${code}`);
});
```

In the unbundled source (likely `electron-plugin/server/php.ts` or similar — confirm path after cloning), the same handler is the symbol to fix.

Adjacent context that matters for the fix:

- The PHP server is spawned at `index.js:2222-2226` after `getPhpPort()` returns a port in 8100–9000.
- `phpPort` is used by Laravel as `APP_URL` and is the renderer's origin. **Any restart MUST reuse the same port** — picking a fresh port via `getPhpPort()` orphans the renderer onto a dead address.
- The `phpServer` instance is returned from `serveApp` (`:2239-2242`) but is **not** added to `state.processes` — `killChildProcesses()` (`:2827-2841`) won't reap it on quit.
- `phpServer.stderr` (`:2233-2254`) only inspects data for the port banner and `[NATIVE_EXCEPTION]:`. All other stderr output is silently dropped — there's no diagnostic when the server dies.

### How to reproduce

1. Build any NativePHP-for-Electron app (or use rfa: github.com/fgilio/rfa).
2. Launch the packaged `.app` from Finder so stdout is discarded.
3. `pgrep -af "build/php/php.*-S 127.0.0.1"` to find the dev server PID.
4. `kill -9 <pid>`.
5. Observe: app window stays open. Renderer's DevTools Network tab shows hundreds of `ERR_CONNECTION_REFUSED` against `livewire-…/update`. No log entry in `~/Library/Application Support/<app>/storage/logs/laravel-*.log`. Cmd+Q may also hang (separate but related issue — see "Related" below).

### Proposed fix

The minimum upstream change: replace the silent close handler with auto-restart-with-backoff using the **same captured port**, plus surfacing.

```js
const SAME_PORT = phpPort; // captured from line 2222
let consecutiveCrashes = 0;
let lastHealthyAt = Date.now();
const stderrRing = []; // bounded ring buffer fed from the existing stderr listener

const restartHandler = (code, signal) => {
  if (state.shuttingDown) return;
  consecutiveCrashes++;
  const delayMs = Math.min(500 * 2 ** (consecutiveCrashes - 1), 8000);
  log.warn('php-server.crashed', {
    code,
    signal,
    consecutiveCrashes,
    lastStderr: stderrRing.join(''),
  });
  if (consecutiveCrashes > 5 && Date.now() - lastHealthyAt < 30_000) {
    log.error('php-server.gave-up');
    BrowserWindow.getAllWindows().forEach((w) =>
      w.webContents.send('nativephp:server-unrecoverable', { code, signal }),
    );
    return;
  }
  setTimeout(() => respawn(SAME_PORT), delayMs);
};

phpServer.on('close', restartHandler);
```

Plus:

1. Add a ring buffer to capture the last ~4 KB of stderr so the close event has something diagnostic to log.
2. Track `phpServer` in `state.phpServer` so the before-quit handler can SIGKILL it directly.
3. Fire a renderer-visible IPC event (`nativephp:server-unrecoverable`) on give-up so apps can show their own UX.
4. Reset `consecutiveCrashes` after the new server has been healthy for ≥30 s.
5. Bypass restart when an `electron-updater` install is in flight (listen for `before-quit-for-update`).

The current `phpServer.on('error', ...)` listener at `:2255-2257` rejects the original `serveApp` promise. After the first restart that promise is resolved, so a respawn-time error has no handler — needs replacement that logs and lets the close handler retry. This is easy to miss; flag it explicitly in the PR.

### Related (worth noting in the same issue or a sibling)

`app.on('before-quit')` at `index.js:2666-2672` calls `stopAllProcesses()` → `stopProcess()` → `killSync(pid, 'SIGTERM', true)` with `tree=true`. The `tree=true` path spawns `pidtree`/`ps` walks that can hang on uninterruptible-sleep children, blocking `before-quit` indefinitely. A 3 s deadline `setTimeout(() => app.exit(0), 3000)` would make Cmd+Q always succeed.

### Workaround we ship locally

Build-time string-replace patch against `vendor/nativephp/desktop/resources/electron/out/main/index.js`, run from `composer post-autoload-dump`. See `scripts/patch-native-electron-main.php` in fgilio/rfa (PR link forthcoming — paste here once filed).

### Why this matters

- Affects every NativePHP-for-Electron app the moment the dev server dies for any reason (OOM, segfault during a request, port conflict on restart, parent reaping race).
- No log surface in production — invisible until the user reports a frozen UI.
- The fix is small and self-contained; the surrounding code already has all the primitives (`callPhp`, `state`, `log`).

## Files to read in the upstream repo before opening the PR

- The unbundled equivalent of `out/main/index.js:2160-2262` (PHP server spawn + close handler).
- The unbundled equivalent of `out/main/index.js:2666-2680` (before-quit + window-all-closed).
- `electron-plugin/server/api/app.js` — `/quit` and `/relaunch` routes. Auth scheme is `x-nativephp-secret` per `electron-plugin/server/api/middleware.js:3` (verified in our local copy).
- The corresponding `.ts` sources if TypeScript is the source of truth — the `dist/` JS is compiled output.

## Suggested PR split

Two commits, optionally two PRs if reviewers prefer:

1. **Capture stderr ring + log on close.** No behavior change. Adds the diagnostic that's missing today. Should land easily.
2. **Auto-restart with backoff, give-up signal, port reuse.** Behavior change; needs upstream discussion of defaults (5 attempts, 30 s window, 8 s max backoff). Carries the IPC event for apps to subscribe to.

If the maintainers want a third, the `before-quit` 3 s deadline (the "Related" section above) is a logical follow-up.

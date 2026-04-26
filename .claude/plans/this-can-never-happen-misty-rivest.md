# Make backend death recoverable and quit always work (v2, post-review)

## Context

RFA wedged: NativePHP's bundled `php -S 127.0.0.1:8100` dev server died silently. Renderer fired 534 consecutive `ERR_CONNECTION_REFUSED` against `livewire-…/update`. Cmd+Q hung. SIGTERM was ignored. SIGKILL recovered.

Three independent failures:

1. **Silent dev-server death.** `vendor/nativephp/desktop/resources/electron/out/main/index.js:2258-2260` is `phpServer.on("close", code => console.log(...))`. No restart, no surfacing. stdout discarded for Finder-launched apps.
2. **No user feedback.** Renderer keeps polling forever; user blames the UI.
3. **Quit wedges.** Cmd+Q → `before-quit` (`index.js:2666-2672`) → `stopAllProcesses()` (`:2413-2417`) → `killSync(pid, 'SIGTERM', true)` with `tree=true` spawns `pidtree`/`ps`/`taskkill` walks that hang on uninterruptible-sleep children. The HTTP `notifyLaravel` calls only fire later inside `proc.on('exit')` (`:2351-2360`); they're not the proximate cause of the wedge.

This plan recovers from all three, organized as three logical pieces (renderer UX, IPC bridge, vendor patch) that ship together as one PR. The implementation order inside the PR still goes piece-by-piece so each can be smoke-tested independently before integration.

## Pre-work (do these in parallel with implementation, not blocking it)

- **Reproduce the wedge.** Launch the packaged app, hang it, run `sample $(pgrep -x rfa) 5` and `spindump` while Cmd+Q is hung. Confirm whether the stuck frames are in `killSync`/`pidtree`/`ps`, in `notifyLaravel` axios, or somewhere else. Record the trace under `docs/incidents/2026-04-25-quit-wedge.md`. The before-quit patch design is finalized after this trace lands; the strawman in this doc is the working hypothesis.
- **File the close-handler bug upstream** at `nativephp/desktop`. Silent `console.log` on `phpServer.on('close')` is wrong for everyone. Reference our patch as the workaround. If upstream merges a clean fix, our patch script collapses to "delete this file."

## Layer 1 — Renderer-side detection and recovery UI (Laravel only, no vendor patch)

Goal: within ~2 s of backend death, user sees a clear "reconnecting" overlay; on recovery the page reloads cleanly; if it doesn't recover the user sees actionable buttons.

**Detection** — two independent signals, both required:

- **Livewire commit hook**: `Livewire.hook('commit', ({ fail }) => fail(({ status }) => /* flag disconnected */))`. Catches Livewire-specific failures including non-2xx responses and morphdom errors that a fetch wrapper would miss. Livewire 4's transport is `fetch` (`vendor/livewire/livewire/dist/livewire.js:5562, :5651`), confirmed by the renderer-disconnect review.
- **Dedicated `/up` poller** (only the poller wraps `fetch`, not global wrapping — global wrapping false-positives on favicons, asset preloads, and Alpine plugins). Single `AbortController`; cancel any in-flight request before the next tick.

**Crucial fixes from review:**

- **Don't use stock `/up`.** `bootstrap/app.php:8` uses bare `withRouting`, so `/up` runs `StartSession`. With `SESSION_DRIVER=database` on SQLite (env confirms it), session-write contention during big diff loads can stall `/up` past a 750 ms timeout and false-flip the overlay. Add `routes/web.php` route `/_rfa/health` returning `200 'ok'`, registered with `->withoutMiddleware([\Illuminate\Session\Middleware\StartSession::class])` and explicitly `->name('rfa.health')`.
- **Status-code aware.** Treat `response.ok === false` as unhealthy, not just network errors.
- **Two consecutive failures before flipping** to `disconnected`. Single 750 ms blip from a slow query shouldn't trip the overlay.
- **Origin check normalizes via `new URL(input, document.baseURI)`** so `Request` objects and relative URLs are handled. Exclude `AbortError` (Livewire cancels on teardown).

**Polling cadence:** 1 s interval, 1.5 s abort timeout (raised from the original 750 ms after the `/up` analysis). Cancel pending request before issuing next.

**Recovery action:** `window.location.reload()`. Reasoning per renderer-disconnect review: `Livewire.navigate` uses fetch and re-trips on flaky recovery; `$wire.$refresh()` keeps the stale snapshot ID against a freshly-restarted backend (404 loop). `window.location.reload()` is the only correct option.

**Draft preservation before reload.** On the review page (`resources/views/pages/⚡review-page.blade.php`), comments in `$comments` are server-backed — not at risk. The textarea contents of `<flux:textarea>` for an in-progress comment ARE at risk. Before calling `window.location.reload()`, the overlay's recovery hook serializes any visible draft textareas to `localStorage` keyed by `${projectSlug}:${fileId}:${anchor}`; on next render, an Alpine init hook reads them back and re-fills if the user lands on the same anchor. If serialization fails, the overlay surfaces "you may have lost a draft" copy. Document the assumption: only un-submitted textarea content is at risk; saved drafts in `$comments` survive.

**UI:**

- New file: `public/js/backend-health.js`. Alpine store `backendHealth` with states `connected | reconnecting | unrecoverable`. Initializes to `connected` so `visitAndLoad`'s `networkidle` resolves under tests (per `tests/Browser/CLAUDE.md` idiom).
- New file: `resources/views/components/backend-health-overlay.blade.php`. Pure Alpine + Tailwind. Justified deviation from `resources/CLAUDE.md`'s Flux-first rule: even a Blade-only Flux component depends on `@fluxScripts` having loaded successfully, and we want the overlay to render even in degraded states. (The reviewer correctly noted not all Flux components need Livewire — but the deviation argument still holds for "depends on the asset pipeline.")
- Edit `resources/views/layouts/app.blade.php`: add `<script src="/js/backend-health.js"></script>` near other store scripts (around lines 7-13), render `<x-backend-health-overlay />` after the find-in-page bar (right before `<livewire:keepalive />` at line 224).
- States rendered:
  - `reconnecting`: spinner + "Reconnecting to backend…" + counter.
  - `unrecoverable` (after 30 s of failed polls OR after the close-handler reports give-up via Layer 3): "Backend won't recover" + **Force Quit** + **Restart RFA** buttons. No "Try Again" button — polling continues indefinitely until success or the user picks an action. Buttons disable on click and show "Quitting…" / "Restarting…"; re-enable after 5 s in case the action silently failed.
- Styling: `bg-gh-bg/95 backdrop-blur`, brutalist headings, `font-display` labels, `font-mono` for the technical "last error" line. `z-[9999]` (matches the find-in-page bar's stacking).

**Force Quit / Restart — wired in Layer 2.** In Layer 1 they no-op (gracefully) so the UI ships even before the IPC bridge lands.

## Layer 2 — `contextBridge` lifecycle bridge (small vendor patches)

Goal: Force Quit and Restart work even when Laravel is dead, without exposing a secret to the renderer.

Per the force-quit review: contextBridge is strictly better than the original `window.__rfaNative` secret-injection design. Strict capability narrowing (renderer can only `forceQuit()` and `restart()`, not arbitrary IPC), no HTTP roundtrip, simpler renderer code, no Blade injection, secret never enters JS land. Drop the entire `window.__rfaNative` injection plan.

**Two existing patch surfaces:**

1. **Preload** — extend the existing `scripts/patch-native-preload.php`. Add a third `contextBridge.exposeInMainWorld('rfaLifecycle', { forceQuit: () => ipcRenderer.send('rfa:force-quit'), restart: () => ipcRenderer.send('rfa:restart') })`. The `ipcRenderer` import is already in scope.
2. **Main process** — new `scripts/patch-native-electron-main.php`. Anchored insertion that registers `ipcMain.on('rfa:force-quit', () => app.quit())` and `ipcMain.on('rfa:restart', () => { app.relaunch(); app.quit(); })` next to the existing `app.on('before-quit', …)` block.

Both calls go through `app.quit()` → `before-quit`, so they benefit from the Layer 3 deadline once it lands. Confirmed at `index.js:232` (the existing `/api/app/quit` route does the same).

The renderer overlay's buttons call `window.rfaLifecycle?.forceQuit?.()` and `window.rfaLifecycle?.restart?.()`. In tests the global won't exist; assert the buttons would call it (use a stub).

**Note on XSS surface:** unchanged from today. Renderer XSS already has full Livewire authority (`addComment`, `discardFileChanges`, `deleteComment`). Adding "can quit/restart the app" is annoying, not a privilege escalation. The actual attack surface is the comment-markdown renderer — flag that for a separate audit, not this work.

## Layer 3 — Auto-restart and quit deadline (the bigger main-process patch)

Goal: PHP server crashes recover automatically; quit always succeeds within 3 s.

This is the riskiest layer. Ship after Layer 1 + 2 are deployed and observable, and after the wedge is reproduced and characterized.

### Auto-restart on `phpServer.on('close')`

**Critical correctness items from the patching review:**

- **Reuse the captured `phpPort` const** (`index.js:2222`), do NOT call `getPhpPort()` again. The renderer's origin is bound to that port via `APP_URL`. A new port silently reorphans the renderer onto a dead address. Comment this loud in the patch.
- **Reassign `state.phpServer = newServer` on every respawn.** Without this, before-quit's SIGKILL targets the dead PID and the live respawn leaks.
- **Re-attach all four handlers** (`stdout`, `stderr`, `error`, `close`) on the new child. The original `error` listener rejects the `serveApp` promise (`:2255-2257`); after restart that promise is long-resolved, so a respawn-time error becomes an `unhandledRejection`. The replacement `error` listener logs and lets the close handler retry.
- **Token-bucket on consecutive crashes after recovery** (per the overall review). Backoff alone allows infinite reconnect-crash-reconnect on slow-fail loops. Keep `consecutiveCrashes` that increments on close and resets only after the new server has been healthy for ≥30 s. Give up after `consecutiveCrashes ≥ 5`.
- **Backoff:** 500 ms → 1 s → 2 s → 4 s → 8 s, capped.
- **Suppress restart on `state.shuttingDown === true`.** Set in the patched before-quit (Layer 3).
- **Suppress restart during auto-update.** Listen for `autoUpdater.on('before-quit-for-update', () => { state.shuttingDown = true; })`. The packaged app uses `electron-updater` (already wired via `config/nativephp.php`), and an update install legitimately exits the dev server.
- **Capture last ~4 KB of stderr in a ring buffer** during normal operation; on `close`, write `{ exitCode, signal, lastStderr, restartAttempts, timestamp }` to `${app.getPath('userData')}/logs/electron-main.log` via `electron-log` (or fall back to a hand-rolled `fs.appendFile` if not in the bundle — verify before patching). Do NOT `notifyLaravel` for this — Laravel may be the dead party.
- **Surface to renderer.** On give-up, send IPC `rfa:server-unrecoverable` with the last error to the renderer; the overlay reads it via a contextBridge `onServerEvent(callback)` to display "last error" copy.

### `before-quit` deadline

Design depends on the wedge reproduction. Strawman based on current evidence:

- Set `state.shuttingDown = true` first.
- SIGKILL `state.phpServer` AND every `state.processes[*].proc.pid` upfront (`SIGKILL`, not the upstream `SIGTERM`-with-tree-walk that's the suspected wedge cause). This intentionally bypasses graceful shutdown of the queue worker — acceptable because the worker uses SQLite, jobs reservations are cleaned by Laravel on next boot, and the alternative is hangs.
- Preserve `clearInterval(this.schedulerInterval)` (don't drop functionality during the replacement).
- Arm `setTimeout(() => app.exit(0), 3000)` (no `unref` — we want the timer to definitely fire if anything stalls past 3 s; clean shutdowns will exit naturally before then via Electron's normal teardown anyway).

If the reproduction shows the actual wedge is `notifyLaravel` axios after all (contradicting the overall review's reading), shift the SIGKILL ordering accordingly. Do not finalize the patch text without the trace.

## Patch script conventions (apply to BOTH the existing preload patch AND the new main-process patch)

The existing `scripts/patch-native-preload.php` has shortcomings the patching review surfaced:

- **`exit(1)` on `import_not_found` / anchor missing.** Currently it `fwrite`s a warning and Composer treats the run as success. CI never fails. Plan must fix the existing script too — the new script can't be the one that introduces this norm.
- **Atomic writes.** `file_put_contents` is non-atomic on power loss; a half-written file can confuse sentinel detection (`already_patched` matches but the patched bytes are partial). Write to `${path}.tmp` then `rename()`.
- **Versioned per-script sentinels.** Use `[rfa patch v1] electron-main` and `[rfa patch v1] preload`, not a shared `[rfa patch]`. Avoids cross-contamination if both scripts touch overlapping anchors in future versions.
- **Whitespace-tolerant matching.** The compiled `index.js` uses 4-space indentation in some blocks and the formatting can shift across NativePHP releases. Use `preg_replace` with `\s+` between tokens for the operations that span multiple lines, not literal `str_replace`.

**Anchors for the new main-process patch (verified against the actual file at the cited line numbers):**

- **Op A (close handler):** anchor on the literal `phpServer.on("close", (code) => {` and replace through the matching `});`. Use regex with `\s*` flexibility.
- **Op B (before-quit):** anchor on `app2.on("before-quit", () => {` (current symbol — minifier alias). Replace block, preserving the `clearInterval(this.schedulerInterval)` line and adding the SIGKILL fanout + 3 s timer.
- **Op C (state.phpServer registration):** anchor on `const phpServer = callPhp(` (single-line prefix). Inject `state.phpServer = phpServer;` after the closing `);`. The respawn closure (Op A's replacement) is what keeps `state.phpServer` current after restarts; Op C only handles the initial assignment.
- **Op D (autoUpdater hook):** anchor on the existing `autoUpdater` setup (verify location during implementation). If autoUpdater isn't trivially anchorable, set `state.shuttingDown = true` from a Laravel-side event listener instead — `app/Providers/NativeAppServiceProvider.php` already listens to `UpdateDownloaded::class` (lines 95–99), so it can `App::dispatch('shutting-down')` to a main-process IPC handler we add.
- **Op E (IPC handlers for `rfa:force-quit` / `rfa:restart`):** anchor on the same `app2.on("before-quit", () => {` block; insert `ipcMain.on(...)` registrations immediately above. Single anchor for both Op B and Op E keeps them wedged together.

## Files to touch

**New files**

- `scripts/patch-native-electron-main.php` — main-process patch. Idempotent, atomic, exits non-zero on missing anchors. Versioned sentinel.
- `tests/Unit/Scripts/PatchNativeElectronMainTest.php` — fixture-based correctness + idempotency + missing-anchor → exit code 1.
- `tests/Unit/Scripts/PatchNativePreloadTest.php` — same pattern, retroactive coverage of the existing script after we add `exit(1)`.
- `public/js/backend-health.js` — Alpine store + Livewire hook + poller.
- `resources/views/components/backend-health-overlay.blade.php` — overlay markup.
- `routes/web.php` (new route) — `/_rfa/health` without session middleware.
- `tests/Browser/BackendHealthOverlayTest.php` — synthetic store flip via `$page->script(...)` (matches `tests/Browser/DraftCommentTest.php:258, 301, 331` idiom). Confirms overlay renders, buttons exist with the expected `data-testid`, and `window.rfaLifecycle?.forceQuit` is the call site (don't fire it).
- `tests/Browser/BackendHealthFalsePositiveTest.php` — assert image-404, AbortError on Livewire teardown, and `<link rel="preload">` failures do NOT flip the store. This is the test that catches detection regressions.
- `docs/incidents/2026-04-25-quit-wedge.md` — sample/spindump trace from pre-work. Updated by the on-call when this happens again.

**Edited files**

- `scripts/patch-native-preload.php` — add `exit(1)` on `import_not_found`; switch to versioned sentinel `[rfa patch v1] preload`; switch to atomic write; add the contextBridge `rfaLifecycle` exposure (Layer 2).
- `composer.json` — add the new patch script to the `post-autoload-dump` chain (after the existing preload patch).
- `resources/views/layouts/app.blade.php` — register the store script + render the overlay component (Layer 1).

**Read-only references**

- `vendor/nativephp/desktop/resources/electron/out/main/index.js:2160-2262` (PHP server spawn block, anchors A/C).
- `vendor/nativephp/desktop/resources/electron/out/main/index.js:2660-2680` (before-quit block, anchor B/E).
- `vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/api/middleware.js:3` (auth scheme — `x-nativephp-secret`, confirmed but moot under contextBridge).
- `vendor/livewire/livewire/dist/livewire.js:5562` (Livewire `fetch` transport).
- `public/js/session-recovery.js` (uses `Livewire.interceptRequest`, not fetch wrapping — corrects the v1 plan's claim).
- `app/Providers/NativeAppServiceProvider.php:49-53` (existing `WindowClosed → App::quit()`).
- `tests/Browser/DraftCommentTest.php:258, 301, 331` (synthetic-script idiom).

## Verification

**Automated**

- `php artisan test --compact --filter=PatchNativeElectronMain` — patch correctness + idempotency + missing-anchor exits 1.
- `php artisan test --compact --filter=PatchNativePreload` — same coverage retroactively.
- `php artisan test --compact --filter=BackendHealthOverlay` — synthetic store flip + button wiring.
- `php artisan test --compact --filter=BackendHealthFalsePositive` — image-404 / AbortError / preload-failure don't flip the store.
- `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan` per project conventions.

**Manual smoke (packaged build, since dev `composer native:dev` uses `artisan serve` not `php -S` — the patched code path is only exercised by the bundled main process)**

1. Tag a pre-release locally and run `php artisan native:build mac arm64 --no-publish --no-interaction`.
2. Open the resulting `.app`. Confirm the overlay is hidden during normal use.
3. `pgrep -af "build/php/php.*-S 127.0.0.1"` → kill the PID with `kill -9`.
4. Within ~2 s, the overlay flips to `reconnecting`. The Electron main process auto-spawns a fresh `php -S` on the **same port**. Within 5 s the overlay disappears and the page reloads cleanly. Confirm `~/Library/Application Support/rfa/logs/electron-main.log` has the close event.
5. Repeat the kill 6 times in quick succession (no recovery time between). Confirm the overlay flips to `unrecoverable` with the captured exit code in the "last error" line.
6. From `unrecoverable`, click **Restart**. App relaunches; new instance comes up healthy.
7. From a fresh launch, kill `php -S` once, wait for recovery, then click **Force Quit**. App exits in <1 s.
8. Quit-while-healthy: Cmd+Q on a normal session. App exits in <100 ms (the deadline timer doesn't add latency on clean shutdown).
9. Reproduce the original wedge per the pre-work trace. Confirm the patched before-quit unblocks within 3 s where the unpatched build hung indefinitely.

## Observability

- **Main process** writes structured JSON lines to `${userData}/logs/electron-main.log`: `server.spawned`, `server.crashed` (with exit code, signal, last 4 KB stderr, restart attempt), `server.recovered`, `server.gave-up`, `quit.deadline-fired`. Rotate at 5 MB / 5 files via `electron-log`'s default config.
- **Renderer overlay** writes a single localStorage key `rfa:last-disconnect` on flip with `{ at, livewireFail?, fetchError? }`. On the next successful boot, an Alpine init hook in the layout posts that key to a Laravel route that logs it to `storage/logs/laravel.log` and clears the key. This means even when the user "just relaunches the app," we get a server-side breadcrumb of the disconnect.
- **No telemetry beyond local logs** — RFA is single-user and offline-first.

## Out of scope

- FrankenPHP / Octane / Roadrunner replacement of `php -S`. Bigger architectural shift; revisit only if the watchdog fires more than once a month.
- Replacing NativePHP's IPC. The existing port-4000 Express server is fine; we just don't lean on it for lifecycle anymore.
- A standalone Bun/Node sidecar watchdog. Patching the main process keeps lifecycle ownership where Electron expects it.
- Diagnosing *why* the dev server died in the original incident. The Layer 3 logging makes the next occurrence diagnose itself.
- Sanitizing comment-markdown rendering. Flagged as the actual attack surface in the force-quit review; tracked separately, not blocking this work.

## Decisions (locked)

- **Ship as one combined PR** — all three pieces land together. Implementation order inside the PR is renderer → IPC → main-process patch so each is smoke-testable in isolation before integration.
- **Auto-reload with best-effort localStorage save for un-submitted draft textareas.** No confirmation modal on recovery; smooth recovery with a known acceptable failure mode (drafts may still be lost in edge cases like storage full or anchor change).
- **Ship the vendor patch in parallel with the upstream issue/PR.** Don't block on third-party timeline. If upstream merges a clean fix later, the patch script is reversible — delete it.

---

## Upstream issue brief (extract to `docs/upstream/nativephp-silent-close-handler.md` as the first execution step)

The user is filing this upstream tomorrow. Everything they need is below. Treat it as the canonical content of the standalone MD file — when execution starts, the first step is `Write` this content verbatim to `docs/upstream/nativephp-silent-close-handler.md`.

### Where to file

Most likely repo: **`NativePHP/electron-plugin`** (the source for the bundled `electron-plugin/dist/...` JS in `nativephp/desktop`). Confirm by checking `vendor/nativephp/desktop/composer.json` or its `package.json` for the upstream `electron-plugin` source URL. Fallback if `electron-plugin` is internal-only: file under **`NativePHP/desktop`** with the same content.

### Title

> PHP dev server can die silently — close handler only logs to discarded stdout, no restart, no surfacing

### Summary

When the bundled `php -S 127.0.0.1:<port>` process exits unexpectedly, the Electron main process logs a single line to stdout and does nothing else. For Finder-launched packaged apps, stdout is discarded, so the user sees a frozen UI with no diagnostic. The renderer keeps issuing Livewire `update` requests against the dead port and gets `ERR_CONNECTION_REFUSED` indefinitely. The only recovery is force-quit + relaunch.

This is a single-point-of-failure for every NativePHP-for-Electron app.

### Where the bug lives

In the compiled bundle the user can read locally: `vendor/nativephp/desktop/resources/electron/out/main/index.js:2258-2260`

```js
phpServer.on("close", (code) => {
  console.log(`PHP server exited with code ${code}`);
});
```

In the unbundled source (likely `electron-plugin/server/php.ts` or similar — confirm path after cloning the upstream repo), the same handler is the symbol to fix.

Adjacent context that matters for the fix:

- The PHP server is spawned at `index.js:2222-2226` after `getPhpPort()` returns a port in 8100–9000.
- `phpPort` is used by Laravel as `APP_URL` and is the renderer's origin. **Any restart MUST reuse the same port** — picking a fresh port via `getPhpPort()` orphans the renderer.
- The phpServer instance is returned from `serveApp` (`:2239-2242`) but is **not** added to `state.processes` — `killChildProcesses()` (`:2827-2841`) won't reap it on quit.
- `phpServer.stderr` (`:2233-2254`) only inspects data for the port banner and `[NATIVE_EXCEPTION]:`. All other stderr output is silently dropped.

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
  log.warn('php-server.crashed', { code, signal, consecutiveCrashes, lastStderr: stderrRing.join('') });
  if (consecutiveCrashes > 5 && Date.now() - lastHealthyAt < 30_000) {
    log.error('php-server.gave-up');
    BrowserWindow.getAllWindows().forEach(w => w.webContents.send('nativephp:server-unrecoverable', { code, signal }));
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

The current `phpServer.on('error', ...)` listener at `:2255-2257` rejects the original `serveApp` promise. After the first restart that promise is resolved, so a respawn-time error has no handler — needs replacement that logs and lets the close handler retry.

### Related (worth noting in the same issue or a sibling)

`app.on('before-quit')` at `index.js:2666-2672` calls `stopAllProcesses()` → `stopProcess()` → `killSync(pid, 'SIGTERM', true)` with `tree=true`. The `tree=true` path spawns `pidtree`/`ps` walks that can hang on uninterruptible-sleep children, blocking `before-quit` indefinitely. A 3 s deadline `setTimeout(() => app.exit(0), 3000)` would make Cmd+Q always succeed.

### Workaround we ship locally (link in the issue once landed)

Build-time string-replace patch against `vendor/nativephp/desktop/resources/electron/out/main/index.js`, run from `composer post-autoload-dump`. See `scripts/patch-native-electron-main.php` in fgilio/rfa (forthcoming PR link to fill in).

### Why this matters

- Affects every NativePHP-for-Electron app the moment the dev server dies for any reason (OOM, segfault during a request, port conflict on restart, parent reaping race).
- No log surface in production — invisible until the user reports a frozen UI.
- The fix is small and self-contained; the surrounding code already has all the primitives (`callPhp`, `state`, `log`).

### Files to read in the upstream repo before opening the PR

- The unbundled equivalent of `out/main/index.js:2160-2262` (PHP server spawn + close handler).
- The unbundled equivalent of `out/main/index.js:2666-2680` (before-quit + window-all-closed).
- `electron-plugin/server/api/app.js` — `/quit` and `/relaunch` routes (auth scheme is `x-nativephp-secret` per `electron-plugin/server/api/middleware.js:3`).
- The corresponding `.ts` sources if TypeScript is the source of truth — the JS in `dist/` is compiled.

### Suggested PR scope

Two commits:

1. Capture stderr ring + log on close. No behavior change. Lands easily.
2. Auto-restart with backoff, give-up signal, port reuse. Behavior change; needs upstream discussion.

The user can split into two PRs if reviewers prefer.

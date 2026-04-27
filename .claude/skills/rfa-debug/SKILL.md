---
name: rfa-debug
description: "Debug a running RFA build. Use when: investigating UI bugs, reading the renderer console, finding which DB/log files the live process is writing to, or distinguishing dev vs packaged-release state."
user_invocable: false
---

# Debug

RFA is a NativePHP/Electron app. PHP runs as a child of the Electron main process; the UI runs in a Chromium renderer.

NativePHP relocates **storage** out of the project on every run via the `LARAVEL_STORAGE_PATH` env var (set in the Electron plugin's `php.ts`). The **database** location depends on build mode (`NativeServiceProvider::rewriteDatabase`):

- Dev (`APP_DEBUG=true`): rewritten to `<repo>/database/nativephp.sqlite`. The empty `database.sqlite` Electron creates in the userData dir is unused.
- Packaged release: `<userData>/database/database.sqlite`.

So the files you `cat` from the project's `storage/` are NOT what the live app is writing — but for the DB in dev, the project copy IS the live one.

## Where the live app actually reads/writes

| | Dev build (`composer native:dev`) | Packaged release |
|---|---|---|
| Storage root | `~/Library/Application Support/rfa-dev/storage/` | `~/Library/Application Support/rfa/storage/` |
| SQLite DB | `<repo>/database/nativephp.sqlite` | `~/Library/Application Support/rfa/database/database.sqlite` |
| Logs | `…/rfa-dev/storage/logs/{laravel,browser}.log` | `…/rfa/storage/logs/{laravel,browser}.log` |

The `rfa-dev` vs `rfa` suffix comes from Electron's `app.getPath('userData')`, which uses the app name set by NativePHP. Don't trust the table — confirm against the live process:

```bash
# PHP web server: php -S 127.0.0.1:<port> server.php (port chosen from 8100-9000).
# The [b] trick keeps pgrep from matching its own command line.
SERVER_PID=$(pgrep -f '[b]uild/php/php.*-S 127\.0\.0\.1' | head -n1)
lsof -p "$SERVER_PID" 2>/dev/null | rg 'sqlite|\.log|cwd'
lsof -a -p "$SERVER_PID" -iTCP -sTCP:LISTEN -P 2>/dev/null   # the -a is required to AND the filters
```

If `pgrep` finds nothing, the dev server isn't running — start it (`composer native:dev`). For a packaged build the binary path differs; widen the pattern with `ps -eo pid,command | rg 'rfa.*-S 127'`.

When copying the SQLite file while the app is running, copy `database.sqlite`, `database.sqlite-wal`, and `database.sqlite-shm` together (NativePHP enables WAL mode in `rewriteDatabase`). Or use `sqlite3 "$LIVE_DB" '.dump'` to snapshot.

## Reading the renderer console (Laravel Boost)

Boost ships an `InjectBoost` middleware that injects a JS shim into HTML responses. The shim wraps `console.{log,info,warn,error,table}` plus uncaught errors and `unhandledrejection`, POSTing them to `route('boost.browser-logs')` (fallback: `/_boost/browser-logs`), which writes via `Log::channel('browser')` to `storage/logs/browser.log`.

**Prerequisites — Boost only injects when ALL hold (see `BoostServiceProvider::shouldRun`):**
- `boost.enabled` is true (default).
- App is in `local` env OR `app.debug=true`.
- Not running unit tests.

In a default packaged release (`APP_DEBUG=false`, `APP_ENV=production`), Boost is OFF and `browser.log` won't grow. This skill is dev-build territory.

Verify the shim landed: in DevTools → Elements, search for `id="browser-logger-active"`, or in the Console you should see `🔍 Browser logger active...` on first paint.

**Where the file lives.** `config/logging.php` pins the `browser` channel to `base_path('storage/logs/browser.log')` so renderer writes (which go through NativePHP's relocated `storage_path`) and Boost's MCP CLI reads (which don't) hit the same file. No symlink needed; just call the MCP `browser-logs` tool.

Each entry includes URL + user-agent (Electron version), so you can tell which build produced it.

**Don't symlink anything inside `storage/`** while the dev runner might boot. NativePHP's Electron main runs `fs-extra.copySync(<repo>/storage, <userData>/storage)` on every launch (`copyStorageFolder` in `vendor/.../electron/out/main/index.js`). A symlink whose target lives inside the destination — e.g. `storage/logs/browser.log → ~/Library/.../rfa-dev/storage/logs/browser.log` — produces a self-pointing link, throws `EEXIST`, the unhandled rejection cancels the rest of the boot chain, and the PHP server is never spawned. Symptom: `php artisan native:run` prints "Running the dev script with npm…" then nothing; Electron is up but no window appears and `lsof -iTCP:8100` is empty.

Sanity-check the pipeline end-to-end:

```bash
# Find the live PHP server's port
PORT=$(lsof -a -p "$SERVER_PID" -iTCP -sTCP:LISTEN -P 2>/dev/null \
       | awk 'NR==2 {split($9,a,":"); print a[2]}')

curl -s -X POST "http://127.0.0.1:$PORT/_boost/browser-logs" \
  -H 'Content-Type: application/json' \
  -d '{"logs":[{"type":"log","timestamp":"'"$(date -u +%FT%TZ)"'","data":["ping"],"url":"test","userAgent":"curl"}]}'
tail -1 "$HOME/Library/Application Support/rfa-dev/storage/logs/browser.log"
```

## Reading server-side errors

Boost's `last-error` and `read-log-entries` tools read the project's `storage/logs/laravel.log`, but the live app writes to `~/Library/Application Support/rfa-dev/storage/logs/laravel.log` (NativePHP relocation). Don't symlink (see boot-trap warning above). Read directly:

```bash
tail -200 "$HOME/Library/Application Support/rfa-dev/storage/logs/laravel.log"
```

If you'd rather the MCP tools "just work" for `laravel.log` too, pin the `single` and `daily` channels' paths the same way `browser` is pinned in `config/logging.php`. Tradeoff: in a packaged release the project's `storage/` is read-only, so any code path that hits `Log::info(...)` would fail. The browser channel is safe to pin because Boost is OFF in production; the default channels aren't.

## Inspecting renderer state directly

When the bug is "I right-clicked X and nothing happened," `console.log` is faster than chasing it through HTML. Have the user paste a probe in DevTools (Cmd+Opt+I in dev build):

```js
const el = document.querySelector('[data-testid="file-header"]');
console.log('rfa-debug:', { found: !!el, alpine: !!el?._x_dataStack });
```

The output lands in `browser.log` automatically via the Boost shim — read it back with the MCP tool instead of asking the user to copy/paste.

## DB queries against the live app

Boost's `database-query` MCP tool uses the project's `.env`, so it queries whatever `DB_CONNECTION`/`DB_DATABASE` resolve to. In dev that's `<repo>/database/nativephp.sqlite` — the same file the live app writes to. In a packaged release it's the userData DB, which the project's `.env` won't normally point at.

For the userData DB, query directly with the sqlite CLI (read-only to be safe):

```bash
LIVE_DB="$HOME/Library/Application Support/rfa/database/database.sqlite"
sqlite3 -readonly "$LIVE_DB" 'select * from projects limit 5;'
```

Don't write to the live DB while the app is running unless you know what you're doing — WAL contention will bite.

# Cloud Setup Review

## Verdict

**Round 2: Approve.**

Codex addressed every actionable finding from round 1. The script is in good shape for both Codex Cloud (`cd /workspace/rfa && bash scripts/cloud-setup.sh` with container caching on) and Claude Code Cloud (cold container via the SessionStart hook). The only "watch this" item is hook-timeout behavior on a true cold start in Claude Code Cloud — worth a single live test, not a code change.

(Round 1 verdict was "Approve with nits"; the changes below resolved those.)

## Round 1 → Round 2 Status

| # | Finding | Status | Evidence |
|---|---|---|---|
| 1 | `composer install` unconditional | Fixed | `scripts/cloud-setup.sh:57` — `[ ! -f vendor/autoload.php ]` gate restored |
| 2 | New Laravel env/migrate steps undocumented | Fixed | `scripts/cloud-setup.sh:73-74` — comment added explaining the contract |
| 3 | `--with-deps` always-on for Linux+root | Fixed | `scripts/cloud-setup.sh:117` — gated behind `RFA_PLAYWRIGHT_WITH_DEPS=1` |
| 4 | Lost rationale comments | Fixed | Pest-plugins (`:54-56`), npm install (`:98-99`), Playwright path (`:115-116`) |
| 5 | Project-dir resolution too loose | Fixed | `scripts/cloud-setup.sh:25-27` — requires `"name": "fgilio/rfa"` in `composer.json` |
| 6 | Playwright binary guard too weak | Fixed | `scripts/cloud-setup.sh:109-111` — `--version` sanity check |
| 7 | Shebang inconsistency | Fixed | Both files now `#!/usr/bin/env bash` |
| 8 | `npm install` rationale | Fixed | Comment at `scripts/cloud-setup.sh:98-99` |
| 9 | mkdir -p database redundancy | Fixed | Removed |

## Round 2 Validation

- `bash -n scripts/cloud-setup.sh .claude/hooks/session-start.sh` → clean.
- `RFA_CLOUD_SETUP_SKIP_DEPS=1 bash scripts/cloud-setup.sh` → installs Lefthook and exits cleanly.
- `composer.json` contains `"name": "fgilio/rfa"`, so the new dir-resolution guard matches.

## Claude Code Cloud — Mental Dry Run

### Cold start

1. Container empty, `CLAUDE_CODE_REMOTE=true`, `CLAUDE_PROJECT_DIR` set.
2. Hook `cd`s, then `bash scripts/cloud-setup.sh`.
3. `resolve_project_dir` picks `CLAUDE_PROJECT_DIR` (composer.json + matching name). OK.
4. Lefthook attempt → likely warns "lefthook not found" on bare image, continues. OK.
5. `composer install` runs (no `vendor/autoload.php`). OK.
6. `.env` ← `.env.example`, `key:generate`, sqlite touch, `migrate --force`. OK.
7. `npm install`, then `playwright install chromium`. OK.

### Warm start (same container, second session)

- `composer install` skipped (autoload exists) — the round-1 perf concern.
- `migrate --force` still runs every time. Idempotent, ~1-2s overhead. Acceptable.
- npm / playwright are no-ops when nothing changed.

## Remaining Nits (none blocking)

### 1. Hook timeout risk on cold start in Claude Code Cloud

- Severity: medium (situational; not a regression vs. previous script)
- Where: SessionStart hook end-to-end
- Problem: Cold `composer install` + `npm install` + `playwright install chromium` can hit 2-5 minutes. If Claude Code Cloud enforces a SessionStart hook budget, the hook is killed mid-bootstrap and the session starts half-installed.
- Why it matters: A half-installed remote session is the worst failure mode — silent and intermittent. Browser tests fail with cryptic errors.
- Suggested mitigation (only if it bites): split into two phases — a one-shot bootstrap (Codex Cloud setup script, Claude Code Cloud post-create / `RemoteSetup` command) does the heavy installs; SessionStart only does Lefthook + a fast "verify deps present" check.

### 2. `migrate --force` runs on every cloud session

- Severity: low
- File: `scripts/cloud-setup.sh:88-89`
- Problem: Idempotent but adds artisan boot + migrations-table query each session.
- Suggested fix: Optional. Only worth it if zero-cost warm starts become a goal. Could gate on "DB empty or migrations pending."

### 3. `grep -Eq '"name"…' composer.json` is line-anchored

- Severity: nit
- File: `scripts/cloud-setup.sh:27`
- Problem: Composer always writes `"name"` on one line, so safe in practice. If a tool ever reformats `composer.json` weirdly, dir resolution fails fast (better than picking the wrong dir).
- Suggested fix: None — current behavior is correct.

### 4. Lefthook absence in cloud is a silent warning

- Severity: nit
- File: `scripts/cloud-setup.sh:37-45`
- Problem: On Claude Code Cloud's bare image, lefthook is unlikely to be on PATH. Current behavior: `Warning: lefthook not found.` and continue. That's correct — git hooks don't matter in cloud sessions.
- Suggested fix: None.

### 5. `install_playwright_browser` silently no-ops if `--version` fails

- Severity: nit
- File: `scripts/cloud-setup.sh:109-111`
- Problem: If `node_modules/.bin/playwright` exists but is broken, browser install is skipped silently and tests fail later.
- Suggested fix: None — trade-off favors silence over hard-failing mid-bootstrap.

## Answers To Review Questions (round 2)

1. **`scripts/cloud-setup.sh` correct & idempotent?** Yes. Composer install is now correctly gated; migrate/key/env are guarded; npm/playwright steps are idempotent.
2. **Hook still preserves Claude Code behavior?** Yes. Local clones still get Lefthook via the SKIP_DEPS branch. Remote sessions get the full bootstrap. The new env/migrate steps are explicit additions, not silent drift.
3. **Works for Codex Cloud as documented?** Yes. `cd /workspace/rfa && bash scripts/cloud-setup.sh` resolves via `PWD`, container caching skips composer install on warm starts, env vars (`PLAYWRIGHT_BROWSERS_PATH`, `COMPOSER_ALLOW_SUPERUSER`) honored.
4. **Project-dir detection too broad / fragile?** No longer. The `"name": "fgilio/rfa"` guard means a stray sibling `composer.json` no longer shadows the real project.
5. **Dependency order correct?** Yes. Lefthook → Composer → Laravel env → npm → Playwright.
6. **Should `.env`/`APP_KEY`/SQLite/migrate be in the shared script?** Yes, with the comment Codex added making the intent explicit. Local sessions opt out via `RFA_CLOUD_SETUP_SKIP_DEPS=1`.
7. **`playwright install --with-deps chromium` safe in cloud containers?** Now opt-in via `RFA_PLAYWRIGHT_WITH_DEPS=1`. Default avoids the apt round-trip.
8. **Security / perf / caching / failure-mode concerns?** Perf and security: clean. Failure mode: cold-start hook timeout is the only real risk (nit 1 above) — situational and not a regression.
9. **`npm install` or `npm ci`?** `npm install`, with the rationale comment now in the file.
10. **PHP/Node version mismatch with CI?** Still not guarded. Optional, not blocking.

## Suggested Codex Cloud Setup

Setup script:

```bash
cd /workspace/rfa
bash scripts/cloud-setup.sh
```

Env vars to keep:

- `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`
- `COMPOSER_ALLOW_SUPERUSER=1`

Optional:

- `RFA_PROJECT_DIR=/workspace/rfa` — make project-dir resolution explicit instead of relying on `PWD`.
- `RFA_PLAYWRIGHT_WITH_DEPS=1` — only if a future Codex image stops shipping the Chromium system libs.

Container caching: keep on. The hook is idempotent and now correctly skips `composer install` when warm.

Agent internet access: keep on. Cold containers need it for composer / npm / playwright fetches.

## Notes

- I did not run a full dependency install (composer/npm/playwright). Validation was limited to `bash -n`, `RFA_CLOUD_SETUP_SKIP_DEPS=1 bash scripts/cloud-setup.sh`, and `git diff` against the previous hook.
- The cold-start hook-timeout question (nit 1) is the one thing not derivable from static review. One real cold-start session in Claude Code Cloud will answer it definitively. If the hook is killed mid-install, fall back to a two-phase bootstrap as suggested.
- Behavior for both clouds now traces through the same script, which is the goal of this change. The opt-out path (`RFA_CLOUD_SETUP_SKIP_DEPS=1` for local Claude Code) keeps fast-start semantics for non-cloud sessions.

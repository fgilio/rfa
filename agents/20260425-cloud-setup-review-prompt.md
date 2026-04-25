# Claude Code Review Prompt - Cloud Setup Unification

Review the cloud setup changes in this repository.

## Goal

We are setting up RFA for both Claude Code Cloud and Codex Cloud. The intended design is one repo-owned setup script that both cloud environments can run, with the existing Claude Code `SessionStart` hook delegating to it.

## Files in scope

Review only these files:

- `.claude/hooks/session-start.sh`
- `scripts/cloud-setup.sh`

Ignore unrelated dirty working tree changes, especially:

- `tests/Browser/SessionPersistenceTest.php`

## Context

Current Codex Cloud environment UI:

- Repository is cloned to `/workspace/rfa`
- Setup script is currently automatic
- Env vars are set in the cloud UI:
  - `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`
  - `COMPOSER_ALLOW_SUPERUSER=1`
- Container image is Codex `universal`
- Container caching is on
- Agent internet access is on

Current Claude Code Cloud setup was previously implemented entirely in `.claude/hooks/session-start.sh`. The new version moves shared logic to `scripts/cloud-setup.sh`.

## Review focus

Please check:

1. Whether `scripts/cloud-setup.sh` is correct and idempotent for cloud sessions.
2. Whether `.claude/hooks/session-start.sh` still preserves previous Claude Code behavior.
3. Whether the script works for Codex Cloud when run as:

   ```bash
   cd /workspace/rfa
   bash scripts/cloud-setup.sh
   ```

4. Whether project directory detection is too broad, too fragile, or likely wrong in either cloud.
5. Whether dependency installation order is correct for Laravel, Composer plugins, npm, and Playwright.
6. Whether `.env`, `APP_KEY`, SQLite file creation, and migrations should be included in this shared script.
7. Whether `npx playwright install --with-deps chromium` is safe and appropriate in cloud containers.
8. Whether there are security, performance, caching, or failure-mode concerns.
9. Whether this script should use `npm install` or `npm ci` in cloud.
10. Whether the script should account for PHP/Node version mismatches with CI.

## Constraints

- Do not modify application code.
- Do not commit.
- Do not review unrelated files.
- Prefer concrete findings over general advice.
- If you run commands, keep them non-destructive unless needed for validation.

## Optional validation

Useful checks:

```bash
bash -n scripts/cloud-setup.sh .claude/hooks/session-start.sh
RFA_CLOUD_SETUP_SKIP_DEPS=1 bash scripts/cloud-setup.sh
git diff -- .claude/hooks/session-start.sh scripts/cloud-setup.sh
```

Avoid a full dependency install unless you think it is necessary.

## Output

Write your review to:

```text
agents/20260425-cloud-setup-review.md
```

Use this format:

```markdown
# Cloud Setup Review

## Verdict

One of:

- Approve
- Approve with nits
- Request changes

## Findings

List findings ordered by severity. For each finding include:

- Severity: blocker, high, medium, low, nit
- File and line
- Problem
- Why it matters
- Suggested fix

If there are no findings, write "No findings."

## Answers To Review Questions

Answer the 10 review focus questions briefly.

## Suggested Codex Cloud Setup

State the exact setup script content to paste into Codex Cloud, plus env vars to keep.

## Notes

Mention anything uncertain or environment-dependent.
```

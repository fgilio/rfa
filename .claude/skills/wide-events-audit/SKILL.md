---
name: wide-events-audit
description: >
  Weekly Claude Code Routine for auditing RFA logging against
  .claude/skills/wide-events/SKILL.md. Runs deterministic logging tests,
  performs agentic review of semantic wide-events rules, writes a markdown
  report, and opens a human-gated PR with the report artifact.
user-invocable: true
disable-model-invocation: true
---

# Wide Events Audit Routine

Audit RFA production logging against `.claude/skills/wide-events/SKILL.md`.
Every run writes a report and opens a human-gated pull request.

## Data Sources

Read only:

- `.claude/skills/wide-events/SKILL.md`
- `tests/Arch/LoggingConventionsTest.php`
- production PHP and Livewire files under `app/` and `resources/views/`
- `config/logging.php`
- test output from the commands in this skill

Write only:

- `reports/wide-events/YYYY-MM-DD.md`

Do not edit application code, tests, or the wide-events standard in this
routine. The PR is an audit artifact, not an auto-fix patch.

## Flow

1. Change into the RFA repo.
2. Read `.claude/skills/wide-events/SKILL.md`.
3. Run deterministic checks:

   ```bash
   php artisan test --compact tests/Arch/LoggingConventionsTest.php
   ```

4. Run the production log inventory:

   ```bash
   rg -n "Log::(debug|info|warning|error|critical)\(|Context::(flush|add)\(" app resources/views config -g'*.php'
   ```

5. Review the inventory using the [Agentic checks](#agentic-checks).
6. Write the report to `reports/wide-events/YYYY-MM-DD.md`.
7. Commit the report on a branch named `audit/wide-events-YYYY-MM-DD`.
8. Push the branch and open a human-gated PR against `main`.

If the deterministic test command fails, still write and open the report.
Mark the failed command as `[CRITICAL]`.

## Agentic Checks

### Logging Ownership

Check whether each canonical `Log::info()` has one clear owner for an
externally meaningful operation.

Flag `[WARNING]` when a child action/service and a parent boundary both emit
canonical info events for the same operation.

Pass when the owner is clear and child services only emit warnings or handled
errors.

### Context Lifecycle

Check `Context::flush()` and `Context::add()` placement.

Flag `[WARNING]` when the logging owner adds context before flushing.

Flag `[WARNING]` when a child warning or handled error flushes context and
would break correlation with the owner event.

Pass when the owner starts clean and child logs preserve the owner context.

### Canonical Event Fields

Check each canonical `Log::info()` event for:

- `rfa.outcome`
- `rfa.duration_ms`
- enough IDs, counts, booleans, and reason codes to debug the operation

Flag `[WARNING]` when `rfa.outcome` or `rfa.duration_ms` is missing.

Flag `[INFO]` when extra context would materially improve debugging but the
event still has the required fields.

### Outcome Semantics

Check `rfa.outcome` values against the standard's vocabulary and decision tree.

Flag `[WARNING]` when a path uses the wrong outcome, such as `completed` for a
partial result or `error` for a normal rejection.

Pass when success, error, skipped, cancelled, rejected, and partial paths are
distinguishable.

### Warning And Error Semantics

Check warning and error logs for stable reason codes and useful payloads.

Flag `[WARNING]` when warnings are used for normal rejected inputs that should
be represented by the owner canonical event with `rfa.outcome = rejected`.

Flag `[WARNING]` when `Log::error()` duplicates an exception that is rethrown
to a logged parent or framework reporter.

Pass when warnings represent degraded behavior and errors represent swallowed
or converted unexpected failures.

### Privacy

Check all log payloads and context fields for private or noisy data.

Flag `[CRITICAL]` for secrets, tokens, file contents, clipboard data, or
exported review text.

Flag `[WARNING]` for raw stderr, raw exception messages, or avoidable absolute
paths.

Pass when logs prefer project slugs, relative paths, hashes, counts, booleans,
and stable reason codes.

### Storage

Check `config/logging.php`.

Flag `[CRITICAL]` when the default channel or recursively resolved default
stack activates Slack, Papertrail, Sentry, syslog, errorlog, or remote Monolog
handlers.

Flag `[WARNING]` when local retention is unbounded or the default local channel
is noisier than intended.

Pass when logs stay local and bounded.

## Output

Write one markdown report:

```markdown
# Wide Events Audit: YYYY-MM-DD

## Summary

- Deterministic checks: pass|fail
- Findings: <critical> critical, <warning> warning, <info> info
- Files reviewed: <count>

## Deterministic Checks

| Command | Result | Notes |
|---|---|---|
| `php artisan test --compact tests/Arch/LoggingConventionsTest.php` | pass|fail | <short note> |

## Findings

### [SEVERITY] Short Title

- **File:** `path/to/file.php:123`
- **Rule:** <wide-events rule or agentic check>
- **Evidence:** <short factual excerpt or paraphrase>
- **Impact:** <why this hurts debugging, privacy, or noise>
- **Suggested fix:** <concrete next step>

## Clean Areas

- <notable areas that passed>

## Residual Risks

- <uncertainties, skipped checks, or limits of agentic review>
```

If there are no findings, keep the same template and write:

```markdown
## Findings

No findings.
```

## Landing Artifacts

This routine always opens a human-gated PR. Do not enable auto-merge for the
PR itself.

Use the runtime's GitHub tooling to:

1. Rename the working branch to `audit/wide-events-YYYY-MM-DD`.
2. Commit only `reports/wide-events/YYYY-MM-DD.md`.
3. Push the branch.
4. Open a PR against `main`.

PR title:

```text
Wide events audit YYYY-MM-DD
```

PR body:

```markdown
Automated weekly wide-events logging audit.

Artifact: `reports/wide-events/YYYY-MM-DD.md`

This PR is human-gated. It contains the report only.
```

If the runtime exposes auto-merge tooling, do not enable it.

## Read-Only Guarantees

The routine reads repository files and test output.

The routine must not:

- push directly to `main`
- edit application code
- edit tests
- edit `.claude/skills/wide-events/SKILL.md`
- call non-GitHub external services
- include secrets or log contents in the report

The only mutation is a report commit on the audit branch and the PR opened for
human review.

## Writeup Guidance

- Be concise. Findings first.
- Use exact file and line references.
- Quote only tiny code excerpts when needed. Prefer paraphrase.
- Use straight apostrophes.
- Do not use em dashes.
- Do not use semicolons.

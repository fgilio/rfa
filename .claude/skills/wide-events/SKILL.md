---
name: wide-events
description: "Wide Event Logging for RFA. Audit, add, or fix logging with canonical info events, Laravel Context fields, local-only storage, CI-enforceable checks, and agent-review rules. Use when changing logs, observability, error handling, actions, services, Livewire side effects, NativePHP listeners, or console commands."
user_invocable: true
---

# Wide Event Logging

Use this skill whenever you add, change, or review production logging in RFA.

Goal: logs should answer "what happened to this unit of work?" without breadcrumb noise, stale context, private data, or remote observability.

## Doctrine

One externally meaningful unit of work gets one canonical `Log::info()` event.

The event name says what happened. Structured context lives in Laravel `Context::add()` fields.

Log outcomes, IDs, counts, booleans, durations, and rejection reasons. Do not narrate internal steps.

## Relationship to Laravel's Context Facade

RFA uses Laravel's `Context` facade as the carrier for canonical-event fields, but deliberately uses only a thin slice of its API: `Context::add()` and `Context::flush()`. The broader facade surface — `scope()`, `addHidden()`, `when()/unless()`, stacks (`push()/pop()`), `increment()/decrement()`, the `dehydrating()/hydrated()` queue hooks, and `#[Context]` attribute injection — is intentionally unused. We are not a queue-driven web app; we are a single-window desktop app, so most of those features solve problems we do not have.

One rule is the deliberate inverse of the facade's usual default. Laravel's headline use case is Context **surviving** across boundaries: it serializes into the queue payload and rehydrates so trace IDs persist. RFA mandates the opposite — `Context::flush()` at the start of every logging owner (see Context Lifecycle) — because NativePHP runs **one long-lived PHP process**. The danger here is not losing context across a hop; it is stale context bleeding from one operation into the next. Same principle (accurate execution metadata), different runtime.

The facade's own guidance still governs *what* belongs in a field: if the code cannot run without the value, pass it explicitly as a parameter; if the value only helps **explain** the execution, it may belong in Context. Context is observability metadata, never hidden business state or an authorization input.

## Enforcement Model

Rules are tagged by enforcement tier:

- **[CI MUST]**: mechanically enforceable. Add or update Pest architecture tests when practical.
- **[Agent MUST]**: semantic rules that Codex, Claude, or a human reviewer must check.
- **[Review SHOULD]**: preferred defaults. Exceptions are allowed when the reviewer can explain why.

Do not rely on agents for regex-level checks. Do not rely on CI for unit-of-work judgment.

## Logging Ownership

**[Agent MUST]** Pick one logging owner per user or system operation. Do not add canonical info events to every eligible layer.

Default owner heuristic:

- **Action** owns the canonical event when a Livewire component or listener delegates the operation to an action.
- **Livewire public method** owns the canonical event only when the side effect or refresh logic lives in the component.
- **Service public method** owns the canonical event only when it is invoked directly by UI, listener, command, or another unlogged boundary.
- **NativePHP listener** owns the canonical event for the callback unless it delegates to a logged action.
- **Console command** owns the canonical event for the command unless it delegates to a logged action.

Child services may emit warnings for recoverable failures, but they should not emit a second canonical info event for the same operation.

## Scope

These rules apply to production paths:

- Actions in `app/Actions/`
- Services at operation boundaries in `app/Services/`
- Livewire components when a public method performs side effects
- NativePHP listeners for deep links, menu events, and updater events
- Console commands

Pure helpers and tiny methods do not need logs.

## Mechanics

### Context Lifecycle

**[CI MUST]** All static `Context` keys start with `rfa.`. This covers every key-taking method the codebase might use — `add`, `addIf`, `addHidden`, `push`, `increment`, `decrement` — not just `add`.

**[Agent MUST]** The logging owner calls `Context::flush()` before adding fields.

**[Agent MUST]** Child warnings and handled errors do not flush context. They should inherit the owner context so warnings can be correlated with the canonical event.

NativePHP runs long-lived PHP processes. Context can bleed between operations, so the owner starts clean and child logs reuse that scope.

```php
Context::flush();
Context::add('rfa.project_slug', $project->slug);
Context::add('rfa.outcome', 'completed');
```

Inline arrays on warning/error logs are exempt from `rfa.` namespacing because they are self-contained payloads.

Children should use inline payloads for transient warning/error details. They may call `Context::add()` only for fields intended to appear on the owner canonical event.

### Canonical Info Events

**[CI MUST]** Every production `Log::info()` call is a canonical event.

**[CI MUST]** `Log::info()` passes a lowercase dot-separated literal event name and no manual context array.

**[Agent MUST]** Canonical events include `rfa.outcome`, `rfa.duration_ms`, and enough IDs, counts, and booleans to debug the operation.

**[Agent MUST]** Emit the canonical event once from the logging owner, usually in `finally`.

**[Agent MUST]** Canonical events include `rfa.duration_ms`. There are no duration exemptions. Near-instant operations should still record the measured duration.

For listener-owned events, duration measures the callback unless the listener has a reliable operation start time. A start time pulled from shared mutable state (e.g. a cache key written by more than one process) is *not* reliable — prefer the callback duration over a value that can be raced.

**[Agent MUST]** A listener-owned unit of work emits its canonical info event on **every** terminal outcome, including failure. If the success paths emit `updater.available` / `updater.downloaded`, the failure path must also emit a canonical `Log::info('updater.failed')` (with `rfa.outcome = error`) from `finally` — not just a `Log::error()`. Otherwise the outcome distribution for that unit is unqueryable, because failures have a different shape than successes.

Use this pattern only in the logging owner:

```php
Context::flush();

$startedAt = microtime(true);
$outcome = 'completed';

try {
    // operation
} catch (Throwable $e) {
    $outcome = 'error';
    Context::add('rfa.error_class', $e::class);
    Context::add('rfa.reason', 'operation_failed');

    throw $e;
} finally {
    Context::add('rfa.outcome', $outcome);
    Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

    Log::info('diff.loaded');
}
```

### Warnings

**[CI MUST]** Warning payloads include `reason`.

**[CI MUST]** No `Log::warning()` / `error()` / `critical()` payload and no `Context` field passes a raw exception message, stack trace, or process stderr as a value (`$e->getMessage()`, `$e->getTraceAsString()`, `$e->stderr`) in any argument position, including the nullsafe `?->` form. Wrap such text in `LogSanitizer::summary()` instead — that wrapper is the one allowed form.

**[Agent MUST]** Warning payloads avoid raw file contents, raw stderr, secrets, and avoidable absolute paths.

Warnings are for invariant violations, fallback behavior, and degraded-but-recoverable paths.

Prefer cheap, privacy-safe diagnostic fields over free text:

- `exit_code` — `App\Exceptions\GitCommandException` exposes `$e->exitCode` (an int). It separates "ref not found" (1) from "not a git repository" (128) from a timeout, which is exactly the triage signal `$e->getMessage()` would otherwise carry, minus the absolute paths the message embeds.
- `error_class` — `$e::class` for a generic `Throwable`. A class name is always safe and tells you the failure mode.
- `stderr_summary` — `LogSanitizer::summary($e->stderr)`. The sanitizer strips ANSI, collapses whitespace, replaces the user's home directory with `~`, and truncates. See `app/Support/LogSanitizer.php`.

```php
Log::warning('git.diff.failed', [
    'reason' => 'diff_process_failed',
    'path' => $relativePath,
    'exit_code' => $e->exitCode,
    'stderr_summary' => LogSanitizer::summary($e->stderr),
]);
```

Do not warn for every normal rejected input. Use the owner canonical event with `rfa.outcome = rejected`. Add a warning only when the rejection is unexpected, security-relevant, or indicates degraded behavior.

### Errors And Criticals

**[Agent MUST]** Use `Log::error()` only when the exception is handled or converted into a non-throwing path.

**[Agent MUST]** Do not log raw exception messages unless they are known safe or redacted. Exception messages often contain absolute paths, SQL, stderr, or user data. Redact with `LogSanitizer::summary()` (the raw-text ban is now CI-enforced — see Warnings). This applies to externally-sourced error fields too, e.g. an updater error's `message`/`stack`.

`Log::error()` is diagnostic. It never replaces the owner canonical info event.

Use `Log::error()` when all are true:

- the failure is unexpected
- the exception will not be rethrown to a logged parent or framework reporter
- extra diagnostic detail is needed beyond the canonical event

If the logging owner rethrows to the framework and there is no parent owner, the owner still emits its canonical info event from `finally`.

If an upstream application unit owns the operation, inner actions/services do not use the canonical pattern. They may add context or emit warnings, then rethrow.

Handled error example:

```php
Context::add('rfa.error_class', $e::class);
Context::add('rfa.reason', 'project_registration_failed');

Log::error('project.registration.failed', [
    'reason' => 'project_registration_failed',
    'error_class' => $e::class,
]);

// The logging owner still emits its canonical Log::info() from finally.
```

Use `Log::critical()` only for data loss risk or hard safety checks, such as session corruption or a failed discard operation after partial filesystem mutation.

### Outcome Vocabulary

**[CI MUST]** `rfa.outcome` literals use only this set:

| Value | Meaning |
|-------|---------|
| `completed` | Finished successfully |
| `error` | Failed with exception |
| `skipped` | Precondition not met, no work done |
| `cancelled` | User or system aborted |
| `rejected` | Input invalid or denied |
| `partial` | Completed with a deliberate degraded or partial result |

Decision tree:

- Finished the requested work: `completed`
- Threw or handled an exception: `error`
- Did no work because a precondition was absent: `skipped`
- User/system aborted before completion: `cancelled`
- Input was invalid or denied: `rejected`
- Returned intentionally incomplete/degraded results: `partial`

### Event Names

**[CI MUST]** Event names are lowercase dot-separated literals with two to four segments.

**[Agent MUST]** Event names describe the completed outcome, usually `domain.past_tense_verb`.

Use three or four segments when a subsystem/object split makes the event easier to query. Prefer the shorter two-segment form when it remains clear.

Good:

- `project.opened`
- `diff.loaded`
- `git.status.loaded`
- `review.file.discarded`
- `review.refreshed`
- `session.saved`
- `updater.downloaded`

Bad:

- `diff.start`
- `refresh.do`
- `Update available`
- `'review.'.$status`

Put variables in context, not event names.

### Privacy

**[CI MUST]** No static `Context` key is named `rfa.absolute_path`, `rfa.full_path`, `rfa.root_path`, `rfa.repo_path`, `rfa.repoPath`, or ends in `.absolute_path`.

**[CI MUST]** No payload value or `Context` field is a raw exception message, stack trace, or stderr (see Warnings → the raw-text ban). Sanitize with `LogSanitizer::summary()`.

**[Agent MUST]** All logs avoid private data.

Never log:

- file contents
- clipboard data
- exported review text
- tokens, credentials, or secrets
- raw stderr
- raw exception messages unless known safe or redacted
- absolute paths in canonical info events

Relative paths are allowed in warning payloads.

Absolute paths are allowed in warning payloads only when no project context can resolve a relative path or when the path itself is the failed input being diagnosed.

Prefer project slugs, relative file paths, hashes, counts, booleans, and stable reason codes.

## Storage

Logs must remain local.

**[Review SHOULD]** The app default channel resolves to local daily files with bounded retention and `info` level:

```php
'default' => env('LOG_CHANNEL', 'daily'),

'daily' => [
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),
    'level' => env('LOG_LEVEL', 'info'),
    'days' => env('LOG_DAILY_DAYS', 7),
    'replace_placeholders' => true,
],
```

**[CI MUST]** Remote channel definitions from Laravel scaffolding may exist, but they are not active in the default channel or default stack.

This is enforced by `tests/Feature/LogChannelPostureTest.php`, which resolves `config('logging.default')`, recursively expands any `stack` driver, and asserts no resolved channel is remote. It needs the resolved Laravel config (env + stack expansion), so it lives in `tests/Feature/` rather than `tests/Arch/`, which runs without app context.

Disallowed active channels:

- `slack`
- `papertrail`
- any driver that sends to syslog or errorlog
- any Monolog handler configured with remote `host`, `port`, `url`, or `connectionString`

## CI Checklist

C1–C7 and C9–C10 live in `tests/Arch/LoggingConventionsTest.php` (file scanners, no app context). C8 lives in `tests/Feature/LogChannelPostureTest.php` (needs resolved config). All are enforced today — keep them passing, and extend them rather than loosening when a new pattern appears.

C1. No `Log::debug()` in `app/` or `resources/views`.

C2. Every production `Log::info()` passes no manual context array.

C3. Every production `Log::info()` event name is a lowercase dot-separated literal with two to four segments.

C4. Every static `Context` key (`add`/`addIf`/`addHidden`/`push`/`increment`/`decrement`) starts with `rfa.`.

C5. Every `Log::warning()` / `error()` / `critical()` payload includes `reason`.

C6. Every static `rfa.outcome` literal belongs to the approved vocabulary.

C7. No static `Context` key is a banned absolute-path key.

C8. Remote logging channels are not part of the default channel or recursively resolved default stack.

C9. No `Log::warning()` / `error()` / `critical()` payload and no `Context` field passes a raw exception message, stack trace, or stderr as a value. Wrapped forms like `LogSanitizer::summary($e->stderr)` are allowed.

C10. No `Log::warning()` / `error()` / `critical()` payload passes the absolute repository root variable `$repoPath`. Use owner `Context`, relative paths, hashes, counts, or stable reason codes instead.

## Agent Review Checklist

Reviewers must check the semantic rules:

A1. There is one logging owner per externally meaningful operation.

A2. Child services do not duplicate a parent canonical event.

A3. The logging owner calls `Context::flush()` before adding fields.

A4. Child warnings and handled errors preserve owner context.

A5. `rfa.outcome` is set correctly on success, error, skipped, cancelled, rejected, and partial paths.

A6. `rfa.duration_ms` exists on canonical events.

A7. Context has enough IDs, counts, booleans, and reason codes to debug the operation without reading code.

A8. Event names describe completed outcomes, usually with a past-tense final segment.

A9. Logs avoid private data, raw stderr, raw exception messages, and avoidable absolute paths.

A10. `Log::error()` is not duplicated when exceptions are rethrown to a logged parent.

A11. `Log::error()` is used only for swallowed or converted unexpected failures where extra diagnostic detail is needed.

A12. Diagnostic detail uses safe fields (`exit_code`, `error_class`, `LogSanitizer::summary(...)`) rather than raw exception text — and the chosen fields actually aid triage, not just satisfy the linter.

A13. A listener-owned unit of work emits its canonical info event on every outcome, including failure (not only `Log::error()`).

## Good Examples

Canonical event with context:

```php
Context::flush();
Context::add('rfa.project_slug', $project->slug);
Context::add('rfa.file_count', count($files));
Context::add('rfa.outcome', 'completed');
Context::add('rfa.duration_ms', 12);

Log::info('review.refreshed');
```

Recoverable warning with sanitized, privacy-safe diagnostics:

```php
Log::warning('git.diff.failed', [
    'reason' => 'diff_process_failed',
    'path' => $relativePath,
    'exit_code' => $e->exitCode,
    'stderr_summary' => LogSanitizer::summary($e->stderr),
]);
```

Listener-owned unit that stays canonical on the failure path (diagnostic `Log::error()` plus a canonical `Log::info()` of the same name from `finally`):

```php
Event::listen(UpdateError::class, function (UpdateError $event) {
    Context::flush();

    $startedAt = microtime(true);

    try {
        Log::error('updater.failed', [
            'reason' => 'updater_error',
            'message' => LogSanitizer::summary($event->message),
            'stack' => LogSanitizer::summary($event->stack, 500),
        ]);
        // ...recover, notify...
    } finally {
        Context::add('rfa.outcome', 'error');
        Context::add('rfa.reason', 'updater_error');
        Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));
        Log::info('updater.failed');
    }
});
```

## Anti-Patterns

```php
Log::debug('Review page refreshed', ['files' => $files]);     // C1: no debug logs
Log::info('Review refreshed for '.$project->name);            // C3: dynamic/prose event name
Log::info('review.refreshed', ['project' => $project->slug]); // C2: info takes no payload array
Context::add('project_slug', $project->slug);                 // C4: key must be rfa.*
Context::add('rfa.error', $e->getMessage());                  // C9: raw exception message
Log::warning('Git diff failed', ['stderr' => $e->stderr]);    // C3 + C5 + C9: prose name, no reason, raw stderr
Context::add('rfa.repo_path', $absolutePath);                 // C7: banned absolute-path key
Context::add('rfa.outcome', 'done');                          // C6: not in the outcome vocabulary
```

## Audit Commands

Run from the repository root. These are preflight checks, not a substitute for Pest architecture tests.

```bash
# All production logs.
rg -n "Log::(debug|info|warning|error|critical)\(" app resources/views -g'*.php'

# Production debug logs. Should be 0.
rg -n "Log::debug\(" app resources/views -g'*.php'

# Info logs that pass manual arrays. Should be 0.
rg -n -U -P "Log::info\((?s:.*?)\[" app resources/views -g'*.php'

# Warning logs. Verify each payload has reason.
rg -n "Log::warning\(" app resources/views -g'*.php'

# Non-namespaced static context keys. Should be 0.
rg -n "Context::(add|addIf|addHidden|push|increment|decrement)\(['\"](?!rfa\.)" app resources/views -g'*.php' -P

# Raw exception text / stderr in any payload position. Review each hit — it is
# allowed only inside LogSanitizer::summary(...); otherwise it is a C9 violation.
rg -n -P "\\\$[A-Za-z_]\w*\??->(getMessage\(\)|getTraceAsString\(\)|stderr\b)" app resources/views -g'*.php'

# Context fields. Review nearby code for owner flush and child preservation.
rg -n "Context::(flush|add)\(" app resources/views -g'*.php'
```

## Agent Review Prompt

Use this prompt when asking Codex, Claude, or another reviewer to enforce the protocol:

```text
Review the logging changes against .claude/skills/wide-events/SKILL.md.

Focus on:
- one logging owner per externally meaningful operation
- no duplicate parent/child canonical info events
- Context::flush() before Context::add() in the logging owner
- child warnings and handled errors preserve owner context
- rfa.outcome uses only the approved vocabulary and matches every path
- rfa.duration_ms exists on canonical info events
- event names are lowercase dot-separated completed outcomes
- enough rfa.* IDs, counts, booleans, and reason codes to debug the operation
- warning logs include reason and use stable reason codes
- no duplicate Log::error() when exceptions are rethrown
- no file contents, clipboard data, secrets, exported review text, raw stderr, raw exception messages, or avoidable absolute paths
- diagnostic detail uses safe fields (exit_code, error_class, LogSanitizer::summary) instead of raw exception text, and the fields actually aid triage
- listener-owned units emit a canonical info event on every outcome, including failure

Return findings only. Include file and line references. If there are no issues, say so and list residual risks.
```

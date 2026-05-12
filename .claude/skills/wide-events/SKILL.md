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

**[CI MUST]** All static `Context::add()` keys start with `rfa.`.

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

For listener-owned events, duration measures the callback unless the listener has a reliable operation start time.

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

**[Agent MUST]** Warning payloads avoid raw file contents, raw stderr, secrets, and avoidable absolute paths.

Warnings are for invariant violations, fallback behavior, and degraded-but-recoverable paths.

Use stable reason codes:

```php
Log::warning('git.diff.failed', [
    'reason' => 'process_failed',
    'path' => $relativePath,
    'stderr_summary' => $sanitizedSummary,
]);
```

Do not warn for every normal rejected input. Use the owner canonical event with `rfa.outcome = rejected`. Add a warning only when the rejection is unexpected, security-relevant, or indicates degraded behavior.

### Errors And Criticals

**[Agent MUST]** Use `Log::error()` only when the exception is handled or converted into a non-throwing path.

**[Agent MUST]** Do not log raw exception messages unless they are known safe or redacted. Exception messages often contain absolute paths, SQL, stderr, or user data.

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

**[CI MUST]** No static `Context::add()` key is named `rfa.absolute_path`, `rfa.full_path`, `rfa.root_path`, `rfa.repo_path`, `rfa.repoPath`, or ends in `.absolute_path`.

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
    'days' => 7,
    'replace_placeholders' => true,
],
```

**[CI MUST]** Remote channel definitions from Laravel scaffolding may exist, but they are not active in the default channel or default stack.

The CI check should resolve `config('logging.default')`. If that channel is a stack, recursively inspect every configured channel in the stack.

Disallowed active channels:

- `slack`
- `papertrail`
- any driver that sends to syslog or errorlog
- any Monolog handler configured with remote `host`, `port`, `url`, or `connectionString`

## CI Checklist

Enforce these with Pest architecture tests where practical:

C1. No `Log::debug()` in `app/` or `resources/views`.

C2. Every production `Log::info()` passes no manual context array.

C3. Every production `Log::info()` event name is a lowercase dot-separated literal with two to four segments.

C4. Every static `Context::add()` key starts with `rfa.`.

C5. Every `Log::warning()` payload includes `reason`.

C6. Every static `rfa.outcome` literal belongs to the approved vocabulary.

C7. No `Context::add()` call uses banned static absolute-path keys.

C8. Remote logging channels are not part of the default channel or recursively resolved default stack.

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

Recoverable warning:

```php
Log::warning('git.diff.failed', [
    'reason' => 'process_failed',
    'path' => $relativePath,
    'stderr_summary' => $sanitizedSummary,
]);
```

## Anti-Patterns

```php
Log::debug('Review page refreshed', ['files' => $files]);
Log::info('Review refreshed for '.$project->name);
Log::info('review.refreshed', ['project' => $project->slug]);
Context::add('project_slug', $project->slug);
Context::add('rfa.error', $e->getMessage());
Log::warning('Git diff failed', ['stderr' => $e->stderr]);
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
rg -n "Context::add\(['\"](?!rfa\.)" app resources/views -g'*.php' -P

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

Return findings only. Include file and line references. If there are no issues, say so and list residual risks.
```

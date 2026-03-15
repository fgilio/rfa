# Benchmark Isolation Plan

## Problem

`rfa:benchmark-perf` was mutating the real local application database. The runner reset state by flushing cache and deleting `projects` plus `review_sessions`, but it did that against the app's normal configured stores.

That is unacceptable for two reasons:

1. Benchmarks are developer tooling, not product behavior. They must never destroy local user data.
2. The current implementation made it too easy to accidentally run a destructive command through normal workflows like `composer test:perf`.

## Root Cause

The benchmark command executed against the same Laravel app configuration used by the local daemon:

- same sqlite database
- same cache store
- same session store

Inside the benchmark runner, `resetState()` intentionally did destructive cleanup to ensure deterministic timings. That cleanup was valid for an isolated benchmark database, but unsafe against the app database.

## Plan

### 1. Isolate benchmark execution from app data

Add a benchmark isolation bootstrap that forces benchmark runs onto a dedicated temp sqlite database and non-persistent cache/session stores.

### 2. Make isolation automatic

Do not require new flags. `php artisan rfa:benchmark-perf` should always isolate itself, both locally and in CI.

### 3. Protect child benchmark processes too

The parent benchmark command spawns child Artisan processes for measurements. Those child processes must receive the same isolation environment explicitly.

### 4. Fail closed

If the benchmark database path is not a temp path with the expected benchmark prefix, abort instead of risking the app DB.

### 5. Add regression coverage

Create a command test that seeds a real sqlite file, runs the benchmark command, and proves the seeded rows still exist afterward.

## Reasoning Behind The Changes

### Why a temp sqlite file instead of `:memory:`

The benchmark command uses child processes. In-memory sqlite databases do not survive across process boundaries, so each child would need a separate bootstrap path anyway. A temp file is simple, explicit, and works the same in local runs and CI.

### Why also switch cache/session to non-persistent stores

The old runner also called `Cache::flush()`. Even if the DB were isolated, leaving cache or session on persistent app-backed stores would still risk wiping unrelated local state. Benchmark runs should be hermetic.

### Why keep `resetState()` at all

The benchmark scenarios need deterministic state between runs. The bug was not the existence of `resetState()`. The bug was that it ran against the wrong storage.

### Why add a hard guard in the runner

Configuration bugs happen. A second line of defense in `PerfScenarioRunner` makes future regressions harder. If someone bypasses the bootstrap, the runner should refuse to touch data.

### Why test the command, not just the helper

The dangerous behavior happened at the command level, especially because of child-process spawning. A command regression test covers the real failure mode instead of only proving a helper behaves correctly in isolation.

## Implemented Changes

- Added `BenchmarkIsolation` to prepare and activate an isolated runtime.
- Updated `BenchmarkPerformanceCommand` so every child benchmark process gets its own isolated temp sqlite database.
- Added a safety guard in `PerfScenarioRunner::resetState()`.
- Added a regression test proving the benchmark command does not delete app `projects` or `review_sessions`.
- Documented the isolation rule in the testing guide.

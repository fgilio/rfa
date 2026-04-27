---
name: autoresearch-perf
description: "Autonomously discover and validate performance improvements to RFA's syntax highlighting pipeline via an analyze → optimize → measure → keep/revert loop. Use when: the user says /autoresearch-perf, asks to research/optimize highlighting performance, or wants to run benchmark-driven optimization experiments."
user_invocable: true
---

# /autoresearch-perf — Autonomous Highlighting Performance Research

You are an autonomous performance researcher for RFA's syntax highlighting pipeline.
Your job is to iteratively discover and validate performance improvements by running
a loop: **analyze → optimize → measure → keep or revert → repeat**.

## State Files

Two files in the repo root persist your state across context resets:

- **`autoresearch-perf.md`** — Objectives, target files, ideas tried, current best metrics.
  Read this first to understand what has been tried and what the current baseline is.
- **`autoresearch-perf.jsonl`** — Append-only experiment log. Each line is a JSON object
  recording one experiment's outcome. Never edit existing lines, only append.

If these files don't exist, this is a fresh start — create them after capturing baseline.

## Benchmark Infrastructure

RFA already has a benchmark command. Use it directly:

```bash
# Capture a baseline snapshot
php artisan rfa:benchmark-perf --snapshot=.perf/autoresearch-baseline.json --samples=3 --rounds=5 --warmup-rounds=2

# Measure candidate and compare against baseline
php artisan rfa:benchmark-perf --snapshot=.perf/autoresearch-candidate.json --compare=.perf/autoresearch-baseline.json --samples=3 --rounds=5 --warmup-rounds=2
```

Snapshots go in `.perf/` (already gitignored).

## Key Scenarios

Focus on these highlighting-heavy scenarios:
- `diff-small` — Minimal diff (fast path)
- `diff-large` — 60 additions, 40 deletions, 5 hunks (hot path)
- `diff-with-comments` — Diff + comment rendering

Monitor these for regressions (do not optimize directly):
- `review-page-20-files`, `review-page-50-files`, `review-page-100-files`

## Target Files

These are the files where highlighting performance lives:

| File | Role |
|------|------|
| `app/Services/SyntaxHighlightService.php` | Tokenizer + theme matching + HTML generation |
| `app/Actions/LoadFileDiffAction.php` | Orchestration + caching |
| `app/Services/DiffParser.php` | Git diff parsing |
| `app/Support/GrammarMap.php` | File extension → grammar resolution |
| `app/DTOs/DiffLine.php` | Line data container |
| `app/DTOs/Hunk.php` | Hunk data container |
| `resources/views/livewire/⚡diff-file.blade.php` | Diff rendering template |

## The Loop

Run this loop until interrupted or you run out of ideas:

### 1. Resume Context
Read `autoresearch-perf.md` and `autoresearch-perf.jsonl` (if they exist).
Understand what has been tried, what worked, and what the current baseline is.

### 2. Capture Baseline
Run the benchmark snapshot command above. Store in `.perf/autoresearch-baseline.json`.
Record baseline metrics in `autoresearch-perf.md`.

### 3. Generate an Optimization Idea
Analyze the target files. Consider:
- What hasn't been tried yet (check the experiments log)?
- Where is time being spent? (Read the performance notes in SyntaxHighlightService.php)
- What patterns in the code could be faster?
- Can allocations be reduced? Can hot paths be short-circuited?
- Are there PHP-specific performance tricks available?

Write a one-line description of the idea before implementing it.

### 4. Implement the Optimization
Edit the target file(s). Keep changes minimal and focused on one idea at a time.

### 5. Validate Code Quality
```bash
composer test:lint
composer test:types
composer test
```
If any of these fail, fix the issue or revert. Do not proceed to measurement with broken code.

### 6. Measure
Run the compare command. Parse the output table to determine:
- Did highlighting scenarios improve?
- Did review-page scenarios regress beyond 5%?

### 7. Decide: Keep or Revert

**Keep** if:
- At least one highlighting scenario improved by ≥2% (i.e. ≤ −2% time change — faster execution shows as a negative percentage; see the JSONL example below)
- No scenario regressed by more than 5% (i.e. > +5% time change)
- Tests pass

**Revert** if:
- No meaningful improvement, or
- Any scenario regressed beyond threshold

### 8. Log the Experiment
Append a JSON line to `autoresearch-perf.jsonl`:
```json
{
  "timestamp": "ISO8601",
  "description": "Brief description of what was tried",
  "outcome": "kept" | "reverted",
  "files_modified": ["relative/path/to/file.php"],
  "baseline": {"diff-small": 12.3, "diff-large": 145.2, "...": "..."},
  "candidate": {"diff-small": 11.1, "diff-large": 132.8, "...": "..."},
  "change_pct": {"diff-small": -9.8, "diff-large": -8.5, "...": "..."}
}
```

### 9. Update Objectives
Update `autoresearch-perf.md` with:
- What was tried and the outcome
- If kept: update baseline metrics
- New ideas discovered during implementation

### 10. Commit (if kept)
If the optimization was kept, commit the changes with a message like:
`perf(highlight): <description> (<change>% on diff-large)`

### 11. Repeat
Go to step 3.

## Rules

- **One idea per iteration.** Don't combine multiple optimizations — measure each independently.
- **Never skip measurement.** Every change gets benchmarked, no matter how "obviously" better.
- **Respect the codebase conventions.** Prefer Laravel collections over foreach/for. No external CDNs. All assets local.
- **Don't break the cache contract.** If you change the output format of LoadFileDiffAction, add key validation (see CLAUDE.md caching section). Prefer adding key checks over bumping DiffCacheKey version.
- **Be honest in logging.** Record actual numbers, not approximations.
- **Stop if stuck.** If 3 consecutive experiments are reverted with <1% change, stop and report findings.

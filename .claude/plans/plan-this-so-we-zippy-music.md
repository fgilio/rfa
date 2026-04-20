# Reactive Branch Selector — Implementation Plan

> Companion to shaping doc: `/Users/fgilio/dev/rfa/.claude/plans/reactive-branch-selector.md`

## Context

RFA's review page shows a stale branch in the header after the user switches branches externally (GitHub Desktop, CLI, IDE). The component mounts once, reads `projects.branch`, and never detects external HEAD changes. "No changes detected" under a stale branch label is indistinguishable from "clean repo" or "desynced app" — the app lies with certainty.

**Shape C (from shaping doc):** reactive by default, sticky when the review has comments. When HEAD diverges from `projects.branch`, auto-follow silently if there are no persisted comments; otherwise show a banner with Switch/Keep. Detached HEAD shows a dismiss-only info banner (real support is V2). Missing target branch shows a banner with a single Switch-to-HEAD action.

**Outcome:** after an external branch switch, the user sees reality at a glance, and an in-progress review is never silently discarded.

---

## Scope

**In V1:**
- New `GetCurrentHeadAction` + `CurrentHeadResult` DTO.
- Review page detects divergence on mount and on poll (5s, focus-gated via `document.hidden`).
- Auto-follow (silent) when target has 0 comments.
- Divergence banner (Switch / Keep) when target has ≥1 comment.
- Detached HEAD: info banner (Dismiss only).
- Missing target branch: banner with Switch-to-HEAD only.
- Dismissal is session-ephemeral; re-fires on next HEAD change, app restart, or repo switch.

**Out of V1:**
- Pin toggle UI.
- Detached HEAD as a valid review target (requires teaching `GetBranchListAction`, `GitMetadataService::getBranches`, and `branch-explorer.js` about non-branch targets).
- Read-only "keep" mode for missing target.

---

## Critical files

### New
- `app/Actions/GetCurrentHeadAction.php`
- `app/DTOs/CurrentHeadResult.php`
- `tests/Unit/Actions/GetCurrentHeadActionTest.php`

### Modified
- `app/Actions/RegisterProjectAction.php` (stop overwriting `branch` on re-registration)
- `resources/views/pages/⚡review-page.blade.php` (props, methods, banner UI, polling island)
- `tests/Unit/Actions/RegisterProjectActionTest.php` (rewrite lines 37-46)

### Reference (don't modify)
- `app/Actions/GetBranchListAction.php` — structural template (`final readonly`, single `handle()`)
- `app/DTOs/BranchEntry.php` — DTO template
- `app/Services/GitMetadataService.php:84-87` — `getCurrentBranch()` returns `"HEAD"` when detached; reuse
- `tests/Unit/Actions/GetBranchListActionTest.php` — test pattern using real temp repo
- `resources/views/pages/⚡review-page.blade.php:838-862` — `change-polling` island, the model for focus-gated polling
- `resources/views/livewire/update-banner.blade.php:203-291` — inline banner styling pattern
- `ResolveCommentAnchorAction` — comments re-anchor by content hash; no session save needed when `projects.branch` changes (verified in shaping doc)

---

## Implementation steps

### Step 1 — DTO

Create `app/DTOs/CurrentHeadResult.php` mirroring `BranchEntry.php`:

- `public readonly ?string $branch` (null when detached)
- `public readonly string $sha`
- `public readonly bool $detached`

Plain readonly constructor promotion; no `toArray()` needed yet.

### Step 2 — `GetCurrentHeadAction` + supporting service methods

Create `app/Actions/GetCurrentHeadAction.php`. `final readonly` with constructor-injected `GitMetadataService`. Single `handle(string $repoPath): CurrentHeadResult`:

- Call `getCurrentBranch($repoPath)`. If result equals `"HEAD"` (detached signal) or is empty, set `$branch = null; $detached = true`. Otherwise `$branch = result; $detached = false`.
- Get SHA by running `git rev-parse HEAD` via `GitMetadataService` (add a thin `getHeadSha(string $dir): string` method if none exists).
- Wrap the whole thing in a try/catch for `GitCommandException`. On failure (e.g. mid-rebase, corrupted repo), return a sentinel result — `new CurrentHeadResult(branch: null, sha: '', detached: false)` — that `resolveDivergenceState` treats as "keep aligned, poll again next tick". Reason: avoid crash-loops while the user's repo is in a transient state.
- Return `new CurrentHeadResult(...)`.

Also add to `GitMetadataService` if absent:

- `getHeadSha(string $dir): string` — wraps `git rev-parse HEAD`.
- `branchExists(string $dir, string $branch): bool` — wraps `git rev-parse --verify refs/heads/<branch>` (returns exit 0 when present). Used by `resolveDivergenceState` to detect `missing_target` cheaply without listing all branches on every tick.

### Step 3 — Stop overwriting branch on re-registration

In `app/Actions/RegisterProjectAction.php` around line 32: remove `'branch' => $this->git->getCurrentBranch($directory)` from the **re-registration** update payload (the path hit when a project already exists). First-time registration (around line 47/57) keeps the `branch` seed. Shape C owns subsequent writes.

**Regression to accept (per shaping R5):** reopening an existing project that has comments will now show a divergence banner instead of silently aligning. Passive reopen of projects with no comments auto-follows silently, same as before.

### Step 4 — Rewrite `RegisterProjectActionTest.php` lines 37-46

Replace `test('updates branch on repeated registration', ...)` with two tests:

- `test('seeds branch on first registration', ...)` — first `handle()` creates project with `branch = 'main'`.
- `test('does not overwrite branch on re-registration', ...)` — after first register on `main`, checkout `feature-x`, re-register, assert `branch` is still `'main'`.

### Step 5 — `GetCurrentHeadActionTest`

Mirror `GetBranchListActionTest.php` structure. Three tests:

- `test('returns current branch for a normal repo', ...)` — init repo on `main`, expect `branch='main', detached=false, sha=<40 hex>`.
- `test('detects detached HEAD', ...)` — init repo, commit, `git checkout <sha>`, expect `branch=null, detached=true, sha=<that sha>`.
- `test('returns the commit sha from rev-parse', ...)` — sanity: SHA matches `git rev-parse HEAD` output.

Uses `InteractsWithTestRepositories::createTempDirectory()` + `initTestRepo()` + `commitTestRepo()`.

### Step 6 — Review-page Livewire component changes

In `resources/views/pages/⚡review-page.blade.php` (the `new class extends Component` block starting near line 100):

**New props** (ephemeral Livewire state):
- `public string $divergenceState = 'aligned';` — one of `aligned|diverged|detached|missing_target`.
- `public array $divergenceContext = [];` — `['target' => ..., 'currentBranch' => ..., 'currentSha' => ..., 'detached' => bool]`.
- `public ?string $dismissedAtHead = null;` — SHA recorded when user dismissed; suppresses banner until HEAD moves past it.

**New methods:**

```
checkHeadDivergence()         // public; called from mount and poll
switchReviewToHead()          // public; U3 / U8 handler
keepReviewing()               // public; U4 handler (sets $dismissedAtHead)
dismissDetachedBanner()       // public; U6 handler
private resolveDivergenceState(CurrentHeadResult $head): void
private autoFollowToHead(string $newBranch): void
private rehydrateForTarget(): void       // extracted helper (see below)
private hasPersistedComments(): bool
```

**Extract `rehydrateForTarget()`** — collapse the mount-end block (`⚡review-page.blade.php:150-163`) into a private helper. Current callers of "reload files + session + trash" duplicate this:

- `mount()` runs it once (replace lines 150-163 with a call).
- `updatedRespectGlobalGitignore()` runs most of it (lines 208-223) — refactor to call the helper for consistency.
- `autoFollowToHead()` calls it.

Helper body = current lines 150-163. One source of truth for "rehydrate the view for the current `$diffFrom`/`$diffTo`/branch".

**Logic tree for `resolveDivergenceState`:**
- Short-circuit: if `$head->sha === ''` (sentinel from `GetCurrentHeadAction` error-catch) → leave state untouched; return.
- If `$head->branch === $this->projectBranch && !$head->detached` → `state = aligned`; return. (Saves the comments query on the common path.)
- If `$head->detached`:
  - If `$dismissedAtHead === $head->sha` → `aligned` (suppressed).
  - Else → `detached`, populate context.
- Else if `GitMetadataService::branchExists($this->repoPath, $this->projectBranch)` returns false → `missing_target`, populate context.
- Else (diverged):
  - If `!hasPersistedComments()` → `autoFollowToHead($head->branch)`; return.
  - If `$dismissedAtHead === $head->sha` → `aligned` (suppressed).
  - Else → `diverged`, populate context.

**`autoFollowToHead($newBranch)` — in-place refresh, no `Livewire.navigate`:**
- **Race guard:** if `$this->projectBranch === $newBranch` → return immediately (handles overlapping polls during a slow rehydrate).
- Persist via `app(UpdateProjectSettingAction::class)->handle($this->projectId, ['branch' => $newBranch]);` (same action used at line 210 for the gitignore setting — verify it accepts the `branch` column when wiring; if it whitelists, extend it or use `Project::where('id', ...)->update(...)` directly).
- `$this->projectBranch = $newBranch;`
- `$this->cachedTarget = null;` (line 171 private — reset so `buildDiffTarget` re-computes).
- Call `$this->rehydrateForTarget()`.
- Do **NOT** `skipRender()` — structural change requires parent re-render (per `resources/CLAUDE.md` "Which actions skipRender" table: same classification as `discardFileChanges`).

**`hasPersistedComments()`:**
- Use the existing `Comment::forProjectOrRepo($this->projectId, $this->repoPath)` scope at `app/Models/Comment.php:39` — handles the `project_id = null` / repo-path-scoped edge case that a naive `where('project_id', ...)` would miss.
- `return Comment::forProjectOrRepo($this->projectId, $this->repoPath)->exists();`
- Coarse by design: any persisted comment counts as "review in progress worth protecting" regardless of `origin_ref`. Sub-millisecond on SQLite with the composite index `(project_id, submitted_at)`.

**Known debt to ship with V1:** unsubmitted textarea input (user typing in a comment box but hasn't saved) is ephemeral client state — `hasPersistedComments()` won't see it, so a silent auto-follow can interrupt it. Acceptable for V1; promote to pinned state in a future iteration if it turns out to matter.

**`mount()` extension:**
- Append `$this->checkHeadDivergence();` as the last line of `mount()` (after `loadTrashedFiles()` / `rehydrateForTarget()`). Must run after initial hydration so auto-follow has proper state to operate on.

### Step 7 — Banner UI in blade

Position: between the header and the files list (roughly after the frosted-glass header block, before `@if(! $this->isCommitMode())` at line 837).

**Use `flux:callout`** (not raw divs). Per `resources/CLAUDE.md:4` — "Always use Flux UI components over raw Tailwind/Alpine when available". `flux:callout` is part of Flux Free (see `vendor/livewire/flux/stubs/resources/views/flux/callout`) and supports an icon, heading/text, and action slot. The `update-banner` raw-div pattern predates the convention; don't replicate it.

Three conditional blocks keyed on `$divergenceState`:

- `@if($divergenceState === 'diverged')` — `flux:callout variant="warning"` with icon `arrow-path` (or `git-branch`). Heading: `Repo switched to {{ $divergenceContext['currentBranch'] }}`. Body: `Still reviewing {{ $divergenceContext['target'] }}`. Action slot: two `flux:button` with `wire:click="switchReviewToHead"` and `wire:click="keepReviewing"`.
- `@elseif($divergenceState === 'detached')` — `flux:callout variant="secondary"` (informational, not alarming). Heading: `Repo detached at {{ $shortSha }}`. Body: `Still reviewing {{ $divergenceContext['target'] }}`. Action slot: single `flux:button` `wire:click="dismissDetachedBanner"`.
- `@elseif($divergenceState === 'missing_target')` — `flux:callout variant="danger"`. Heading: `Review target {{ $divergenceContext['target'] }} no longer exists`. Action slot: single `flux:button` `wire:click="switchReviewToHead"`.

Branch names and SHAs render in `font-mono`; headings use display font (Flux defaults). Use `gh-*` color tokens if any overrides are needed.

### Step 8 — Focus-gated polling island

Mirror `change-polling` island at lines 838-862. Add a second Alpine island near the root of the rendered template (e.g., right after the banner). **Critical: add `wire:key`** so the island survives parent re-renders after `autoFollowToHead` without re-initializing the `setInterval`:

```blade
<div wire:key="head-divergence-polling" data-testid="head-divergence-polling" x-data="{
    interval: null,
    start() {
        this.interval = setInterval(() => {
            if (!document.hidden) $wire.checkHeadDivergence();
        }, 2000);
    }
}" x-init="start()" x-destroy="clearInterval(interval)"></div>
```

Interval: **2000ms** (per shaping decision). For reference, the existing `change-polling` island runs at 60000ms — HEAD checks run ~30× more often, but each is a cheap `symbolic-ref` + `rev-parse` (sub-ms) plus an `exists()` against an indexed column. `document.hidden` (not `hasFocus()`) so devtools focus doesn't stop the poller.

Rationale for using an Alpine island instead of `wire:poll` (codebase precedent): `wire:poll` has no predicate for focus-gating; the `setInterval` + `document.hidden` pattern is already established in `change-polling`.

### Step 9 — Livewire component test

Add feature test under `tests/Feature/Pages/` (or wherever page tests live — check existing structure) covering:

1. `aligned` state: mount with HEAD matching `projects.branch`, assert `divergenceState === 'aligned'` and no banner rendered.
2. `diverged` + no comments: mount with HEAD different from `projects.branch`, 0 comments → assert auto-follow ran (branch updated, files reloaded), `divergenceState === 'aligned'`.
3. `diverged` + ≥1 comment: assert `divergenceState === 'diverged'`, banner visible.
4. `detached`: HEAD detached → `divergenceState === 'detached'`.
5. `missing_target`: `projects.branch = 'gone'` → `divergenceState === 'missing_target'`.
6. **Dismiss-then-HEAD-moves transition** (the state-machine test): user dismisses at SHA A → banner hidden; HEAD stays at A → poll tick keeps it hidden; HEAD moves to SHA B → poll tick re-shows banner.
7. `switchReviewToHead` action: persists new branch, clears banner.

Use `Livewire::test(ReviewPage::class, ['slug' => ...])`, drive state via `set()` and `call()`, assert via `assertSet()` and `assertSee()`.

---

## Verification

End-to-end steps before considering V1 done:

1. `composer test:lint` — Pint clean.
2. `composer test:types` — PHPStan clean.
3. `composer test` — Pest passes, including:
   - `GetCurrentHeadActionTest` (new)
   - `RegisterProjectActionTest` (rewritten)
   - Review-page feature test (new)
4. Manual (via `./rfa`):
   - Open a repo on branch `main` with no comments → verify no banner, branch chip reads `main`.
   - Externally `git checkout -b feature-x` → within 5s, branch chip reads `feature-x` (silent auto-follow).
   - Add a comment on `feature-x`. Externally `git checkout main` → within 5s, divergence banner appears. Branch chip still reads `feature-x`.
   - Click **Keep reviewing feature-x** → banner dismisses. Wait ≥5s, banner stays dismissed.
   - Externally `git checkout dev` → within 5s, banner re-appears (new HEAD).
   - Click **Switch review to dev** → branch chip reads `dev`, comments re-render (possibly as "unplaced" if files changed), no banner.
   - Externally `git checkout <sha>` (detached) → within 5s, detached-info banner appears.
   - Click **Dismiss** → banner hides. Move HEAD elsewhere → banner logic re-evaluates.
   - Delete the target branch externally (`git branch -D <target>`) → missing-target banner appears.
   - Relaunch RFA while diverged with comments → banner re-appears on mount (ephemeral state reset).

---

## Risks / open items to handle during implementation

- **`UpdateProjectSettingAction` column whitelist.** Used at line 210 for `respect_global_gitignore`. Before reusing for `branch`, open the action and confirm it doesn't whitelist fields; if it does, extend it (preferred) rather than bypassing to raw Eloquent.
- **`branch-explorer` child re-render.** Line 771-778 passes `:current-branch="$projectBranch"`. When `autoFollowToHead` re-renders the parent, the child prop updates naturally (no `#[Reactive]` needed). Spot-check after implementation.
- **Poll interval 2000ms.** Matches shaping decision. Easy to adjust to 5s later if it turns out to be noisy; query is cheap and focus-gated.
- **Unsubmitted comment drafts.** Ephemeral textarea state isn't protected from auto-follow. Known debt; worth tracking if it bites in practice.

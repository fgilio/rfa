# Plan: "Since the beginning" — review the entire repo as one diff

## Context

RFA lets you review git diffs and comment on them. Today the broadest scoped
view is **"Since {base}"** — every commit on the branch plus uncommitted changes,
collapsed into a single diff (`git diff <merge-base>` against the working tree).

We want a sibling that goes all the way back: **the entire repository as one
cumulative diff** — every line currently in the repo (committed + uncommitted),
rendered as pure additions, so you can comment anywhere in the codebase.

- **Name:** "Since the beginning" (chosen for symmetry with "Since {base}").
- **Semantics (locked):** cumulative — one combined diff, *not* commit-by-commit.
- **Mechanism:** `git diff <empty-tree>` against the working tree. The empty-tree
  hash (`4b825dc642cb6eb9a060e54bf8d69288fbee4904`) is the universal "before
  nothing" ref. Diffing from it = the whole codebase. This is exactly
  "Since {base}" with `from` pushed back from the merge-base to the empty tree.

The empty-tree approach is preferred over `<root-commit>^` because a repo can
have **multiple root commits** (orphan branches, merged histories); the empty
tree captures everything regardless. `DiffTarget::EMPTY_TREE_HASH` already exists.

> This plan was reviewed by Codex (focus: edge cases, maintainability,
> over-engineering). Its findings are folded in — notably two High-severity
> safety issues (§ "Safety") and a simpler architecture (reuse `RangeToWorking`
> instead of a new enum kind). See "Decisions" for the resolved trade-offs.

## What already works (verified, no change needed)

The existing range-to-working pipeline carries the empty-tree hash end to end:

- **Route** `/p/{slug}/rw/{rangeFromWorking}` regex `[0-9a-fA-F]{4,40}\^?` already
  matches the 40-char empty-tree hash.
- **`ResolveRangeToWorkingAction`** calls `resolveRefExpression(from)`. For the
  empty-tree hash, `resolveRef` returns `null` (it forces `^{commit}` and the
  empty tree is a *tree*, not a commit), so `resolveRefExpression` **gracefully
  falls back to the raw string** → `DiffTarget::rangeToWorking('4b825dc…')` →
  `git diff 4b825dc…`. Verified: produces the full repo (623 files here).
- **`GitDiffService`**: `to === null` ⇒ working-directory mode ⇒ untracked files
  included too. Correct for "entire repo".
- **Caching**: `to === null` ⇒ `isImmutable() === false` ⇒ treated as a
  working-tree diff (re-computed, not pinned). Correct.
- **`persistCurrentView`** already maps the `rangeFromWorking` arrival to
  `LastViewKind::RangeToWorking` (when not since-base). We reuse that kind — no
  persist-logic change, no `PersistProjectViewAction` change.

## The restore trap (and its localized fix)

`GitMetadataService::resolveRef()` appends `^{commit}`, returning `null` for the
empty-tree hash. Harmless in `mount` (graceful fallback above), but on **restore**
`ResolveProjectEntryUrlAction::buildRangeToWorkingUrl()` gates on `refExists()`
(= `resolveRef !== null`). Persisted as `RangeToWorking` with `from = empty-tree`,
re-entry would fail that check and **silently drop back to the working tree**.

→ Fix is one early return, not a new enum: in `buildRangeToWorkingUrl()`,
special-case `DiffTarget::EMPTY_TREE_HASH` (it always exists) and build the URL
without the `refExists` gate. "Since the beginning" is a literal *immutable*
diff-from, so — unlike `SinceBase`, which must re-resolve the merge-base over
time — it needs no dedicated `LastViewKind`. (Do **not** teach `resolveRef()` to
return tree objects; every other caller expects a commit.)

## Safety (Codex High-severity findings — both verified)

### A. Suppress the discard button in this mode (data-loss risk)
`file-header.blade.php:73` shows "Discard changes" whenever `diffTo === null`. In
"Since the beginning" every tracked file is `status === 'added'` & not untracked,
so `DiscardFileChangesAction::executeDiscard()` line 85 runs `git rm -f -- <path>`
— a clean **committed** file gets removed from the worktree/index. Discard is
coherent in working-tree / since-base / `/rw/<commit>` views (the diff reflects
real user changes); it is meaningless and destructive when `from` is the empty
tree.
- **Fix:** thread an `allowDiscard` (false when `diffFrom === EMPTY_TREE_HASH`)
  from `⚡review-page` → `⚡diff-file` → `<x-diff.file-header>`, and gate the
  `@if` at `file-header.blade.php:73` on it. Targeted to empty-tree; leaves all
  other modes untouched. (Broader "explicit can-discard concept" is noted as
  future hardening, out of scope.)

### B. Don't let the explorer's Apply mangle the mode
When active on `/rw/<empty-tree>`, reopening the branch-explorer rehydrates
selection (`branch-explorer.js:482`) by filling `selectedHashes` with all
*loaded* commits (`_hashesInRange` already special-cases `EMPTY_TREE_HASH` at
line 524). With pagination or multiple roots, pressing **Apply** rewrites the
view to `/rw/<oldest-loaded>^` — silently losing "entire repo".
- **Fix:** add an explicit `sinceBeginningActive` getter. It must be
  `activeCommitHash === null && activeDiffFrom === EMPTY_TREE_HASH` — **not** just
  the empty-tree check: a root-commit view (`/c/{root}`) also has
  `diffFrom === EMPTY_TREE_HASH` because `DiffTarget::commit()` uses the empty
  tree as a parentless root's parent (`DiffTarget.php:26`), and the page passes
  `diffTo` as `activeCommitHash` (review-page:1288). The `activeCommitHash ===
  null` clause disambiguates (since-beginning has `diffTo === null`; a root-commit
  view has `diffTo === <hash>`). In the rehydrate path, when active, leave
  `selectedHashes = []` / `workingTreeSelected = false` so Apply is a no-op until
  the user makes a deliberate new selection. Highlight the new row (§ Affordance)
  as active in that state.
  - The PHP-side `isSinceBeginningView` is already safe: it requires
    `$this->diffTo === null`, which a root-commit view never satisfies.

## Changes

### 1. Page — `resources/views/pages/⚡review-page.blade.php`
- New `public bool $isSinceBeginningView = false;`.
- Compute it **once after `diffFrom`/`diffTo` are resolved in `mount()`** (right
  after the mode branches, near line 245), inline — no Action, no git:
  `$this->isSinceBeginningView = $this->diffTo === null && $this->diffFrom ===
  DiffTarget::EMPTY_TREE_HASH;`. (Per Codex: `rehydrateForTarget()` is the wrong
  hook — it only reloads lists/session.)
- **Header label** (match at line 1268): add an arm *before* the generic
  `$diffTo === null` arm:
  `$isSinceBeginningView => ['Since the beginning', 'Entire repository (every commit + uncommitted)']`.
- Pass `allowDiscard` (= `! $isSinceBeginningView`) down to the `<livewire:diff-file>`
  children, and add `is_since_beginning_view` to the `page.review.mounted`
  diagnostics payload (line 257) and `Context::add` (line 531), beside the
  since-base flag.
- Persist logic at line 278 is **unchanged** — empty-tree persists as
  `RangeToWorking` (since-base detection returns false for the empty tree because
  `resolveRef` can't commit-resolve it).

### 2. Restore — `app/Actions/ResolveProjectEntryUrlAction.php`
In `buildRangeToWorkingUrl()`, before the `refExists` gate: if `$fromBase ===
DiffTarget::EMPTY_TREE_HASH`, return the `review-page.range-to-working` route with
`rangeFromWorking => DiffTarget::EMPTY_TREE_HASH`. (Import `DiffTarget`.)

### 3. Discard gate — `⚡diff-file` + `<x-diff.file-header>`
Add an `allowDiscard` prop (default `true`) on `⚡diff-file.blade.php`, forward it
to `<x-diff.file-header>`, and change the discard `@if` at `file-header.blade.php:73`
to also require `$allowDiscard`. Default `true` keeps every existing call site
behaving identically.

### 4. Affordance — branch-explorer (the actual entry point)
Per `pages/CLAUDE.md`, a mode exists only once something navigates to it. This
feature adds the "Since the beginning" row **and** makes the picker more
predictable by giving "Since {base}" a stable, always-present position.

**Predictability principle (this picker):** scope-selection affordances keep a
fixed position and are *disabled with a one-line reason* when unavailable —
never removed. Removing a row shifts the layout and makes the option feel like it
doesn't exist; a disabled row with a reason teaches what it is and why it's off.

#### 4a. "Since the beginning" row (new)
- **`public/js/branch-explorer.js`**: add `viewSinceBeginning()` →
  `Livewire.navigate(\`/p/${this.projectSlug}/rw/${EMPTY_TREE_HASH}\`)` reusing the
  **existing** `EMPTY_TREE_HASH` constant (no new literal). Direct navigation like
  `viewWorkingTree()` — not the selection/Apply path. Add the `sinceBeginningActive`
  getter and the rehydrate guard from § Safety B.
- **`⚡branch-explorer.blade.php`**: a row just after the "Since {base}" block
  (~line 444), `data-testid="since-beginning-row"`, label "Since the beginning",
  subtext "entire repository". **Always available and enabled** — empty-tree →
  working tree is well-defined on any branch and needs no base config. Active
  highlight bound to `sinceBeginningActive`.

#### 4b. "Since {base}" — always shown, disable-with-reason (predictability)
Today `sinceBaseRowVisible` (branch-explorer.js:141) hides the row in **two**
cases: when the picker browses a non-current branch, and when `state ===
on_base_branch`. The other states (`up_to_date`, `missing_ref`, `not_configured`)
already render with text. Make the row **always render** in a fixed position with
a unified actionable-vs-disabled presentation across all six states:

| Condition | Presentation | Reason line |
|---|---|---|
| `ready` (on current branch) | **enabled / clickable** | "{N} commits + uncommitted changes" |
| `up_to_date` | disabled | "no commits ahead" |
| `missing_ref` | disabled | "base ref not found locally — run `git fetch`" |
| `not_configured` | disabled | "set a base branch in project settings" |
| `on_base_branch` (NEW — was hidden) | disabled | "you're on the base branch" |
| browsing a different branch (NEW — was hidden) | disabled | "compares against your current branch ({currentBranch})" |

- **`branch-explorer.js`**: replace `sinceBaseRowVisible` (now effectively always
  true) with `sinceBaseActionable` (`state === ready && selectedBranch ===
  currentBranch`) and a `sinceBaseReason` getter returning the per-state reason
  string from the table. `selectSinceBase()` early-returns unless
  `sinceBaseActionable` (already guards on `Ready`; add the branch check).
  - **Null-safety:** the component initializes `branchBase` to `null`
    (`⚡branch-explorer.blade.php:53`); today the outer `x-if="sinceBaseRowVisible"`
    shields the inner `$wire.branchBase.state` reads until data loads. Removing
    that guard means `sinceBaseActionable` / `sinceBaseReason` and every
    label/subtext binding must handle `!this.$wire.branchBase` without touching
    `.state` / `.baseBranch` — treat a null `branchBase` as disabled (loading /
    `not_configured`-style), never throw.
- **`⚡branch-explorer.blade.php`**: collapse the four existing per-state `x-if`
  templates (lines ~361–443) into one always-rendered row — clickable when
  `sinceBaseActionable`, otherwise the shared disabled treatment (dimmed, no
  checkbox, `aria-disabled`, reason via `sinceBaseReason`). This *reduces* template
  branching while covering two more states. Mirror the disabled styling already
  used by the working-tree checkbox's `!workingTreeSelectable` state for
  consistency.

#### 4c. Follow-up issue (app-wide audit)
Scope here is the picker only. File a GitHub issue (repo `fgilio/rfa`, `gh issue
create`) capturing the broader principle for a later pass — created during
implementation, not in plan mode. Draft body:

> **Title:** Predictable UI: disable-with-reason instead of hiding affordances
> **Body:** The branch-explorer now keeps scope options ("Since {base}", "Since
> the beginning", working tree) in a fixed position and disables them with a
> reason when unavailable, rather than hiding them (PR: this feature). Audit the
> rest of the app for affordances that appear/disappear based on state — header
> buttons, native menu items (`HandleMenuItemClicked`), settings entries, remote
> actions — and convert conditional *hiding* to *disable-with-reason* where the
> option is real but currently unusable. Goal: a stable, learnable layout where
> nothing silently vanishes. Out of scope: options that are genuinely
> inapplicable (not merely unavailable).

## Tests (mirror existing siblings)

- `tests/Unit/Actions/ResolveProjectEntryUrlActionTest.php` — a session persisted
  as `RangeToWorking` with `from = EMPTY_TREE_HASH` restores to `/rw/{empty-tree}`
  (regression guard for the `refExists` trap). Template: existing since-base test.
- `tests/Unit/Livewire/ReviewPageRangeAndSelectionTest.php` — mounting
  `rangeFromWorking = <empty-tree>` sets `isSinceBeginningView = true`, renders
  "Since the beginning", and the diff-file children receive `allowDiscard = false`.
- **Discard guard test** — assert no discard affordance / the discard `@if` is
  false when `diffFrom === EMPTY_TREE_HASH`, true otherwise (the data-loss guard).
- `tests/Feature/ReviewPageRoutesTest.php` — the `rw` route resolves the empty-tree
  hash and the page mounts with the full file set; **plus an unborn-HEAD repo**
  (no commits) mounts `/rw/<empty-tree>` without error.
- `tests/Js/branch-explorer.test.js` — `viewSinceBeginning()` navigates to the
  empty-tree `rw` URL; the row is always visible; reopening while
  `sinceBeginningActive` leaves `selectedHashes` empty so Apply is a no-op.
- **Predictable "Since {base}" tests** (`branch-explorer.test.js` +
  `tests/Browser/`): the row renders for **all six** states; `sinceBaseActionable`
  is true only for `ready` on the current branch; `sinceBaseReason` returns the
  right line per state; the disabled row ignores clicks (no navigation) for
  `on_base_branch` and the different-branch case (the two newly-shown states).
- `tests/Unit/DTOs/DiffTargetTest.php` — `rangeToWorking(EMPTY_TREE_HASH)` →
  `['diff', '4b825dc…']` and `isWorkingDirectory() === true`.
- **JS/PHP constant parity** — a small test asserting the JS `EMPTY_TREE_HASH`
  equals `DiffTarget::EMPTY_TREE_HASH` (cheap guard against drift since the JS
  layer can't import the PHP constant).

## Verification (end to end)

1. `composer test:lint && composer test:types`
2. `php artisan test --compact` (or `--filter` the files above).
3. Manual via `composer native:dev`:
   - Open a repo → branch-explorer → "Since the beginning" → whole codebase
     renders as additions; header reads "Since the beginning"; **no discard
     buttons** on file headers.
   - Comment, navigate away, re-enter the project → restores to "Since the
     beginning" (not working tree) — the `refExists` regression.
   - Reopen the explorer while in this mode → the row shows active, no commits
     pre-selected, Apply does nothing → mode preserved (Safety B).
   - Repo with an orphan/second root branch → every root's files appear.
   - Unborn repo (no commits) → the row mounts without error.
   - "Since {base}" predictability: on the base branch, with no base configured,
     with a missing ref, and while browsing a different branch → the row is
     **present but disabled** with the right reason each time (never missing).
4. After approval: `gh issue create` for the app-wide predictability audit
   (§ 4c), then paste the issue URL into the PR description.

## Decisions (resolved with Codex)

1. **No dedicated `LastViewKind`.** Reuse `RangeToWorking`; fix restore with the
   one-line `EMPTY_TREE_HASH` special-case in `buildRangeToWorkingUrl()`. Less
   state, no persist/`PersistProjectViewAction` change. The empty tree is an
   immutable diff-from, so the re-resolution rationale that earns `SinceBase` its
   own kind doesn't apply here.
2. **No `IsSinceBeginningViewAction`.** It's a constant compare — inline it.
   (`IsSinceBaseViewAction` earns its class by shelling out to merge-base.)
3. **Reuse the existing JS `EMPTY_TREE_HASH` constant**; add the parity test so it
   can't drift from the PHP constant. (Passing it from Blade was considered but
   the constant is already used in `_hashesInRange`; one source within JS + a
   drift guard is the lighter call.)
4. **Big-repo guardrail.** Diff bodies already lazy-load per file on intersect, so
   the cost is bounded at view time. The open risk is the file-header *count*
   (one Livewire `diff-file` per repo file — cf. the `TooManyComponentsException`
   note in `resources/CLAUDE.md`). **Recommend** a soft preflight: if the
   resolved file count exceeds a threshold, show a one-line "Entire repo — N
   files" notice. Treat a hard cap as out of scope unless testing on a large repo
   shows a real failure.
5. **Unborn HEAD.** `/rw/<empty-tree>` avoids `git diff HEAD` (that runs only in
   default working-tree mode), and divergence/commit-log paths already rescue to
   sentinels/empties. Covered by the explicit unborn-HEAD tests above rather than
   new guards.

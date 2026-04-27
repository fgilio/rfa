# Test Coverage Analysis

Refreshed against `main` at the time of writing. Counts come from
`grep -rE '^(test|it)\(' tests/...`.

## Current State

| Suite                         | Files | Test cases |
|-------------------------------|-------|------------|
| Unit / Actions                | 39    | 231        |
| Unit / Livewire               | 11    | 162        |
| Unit / DTOs                   | 5     | (in 323)   |
| Unit / Services               | 5     | (in 323)   |
| Unit / Models, Migrations, Observers, Providers, Scripts, Support, root | 14 | (in 323) |
| Unit (non-Action, non-Livewire total) | 29 | 323 |
| Arch                          | 14    | structural |
| Browser (Pest 4 / Playwright) | 28    | 153        |
| Feature                       | 3     | 11         |
| Performance                   | 1     | 2          |

**Well-covered hot spots**

- `GitDiffService`, `GitMetadataService`, `ReviewPage` (split across five
  Livewire test files), `LoadFileDiffAction`, `DiffParser`,
  `SyntaxHighlightService`, `ListProjectsAction`, `GetProjectStatusAction`.
- Comment lifecycle is exercised through `AddCommentAction`,
  `UpdateCommentAction`, `DeleteCommentAction`, `CommentPoolTest`, and the
  comment-pool migration tests.
- Architecture rules pin layer boundaries, naming, view conventions, the
  no-external-resources guarantee, and session-recovery shape.

---

## Remaining Gaps

### 1. Untested actions

| Action                          | Why it matters                                                                 | Priority |
|---------------------------------|--------------------------------------------------------------------------------|----------|
| `ResolveCommitAction`           | Resolves refs and chooses parent / empty-tree as `from` — null + merge paths.  | Medium   |
| `UpdateProjectSettingAction`    | Filters writes through `ALLOWED_ATTRIBUTES` allow-list.                        | Medium   |
| `GetFileCopyContentAction`      | `match` over `kind` for clipboard payloads (diff/original/new).                | Low      |
| `OpenProjectFromPathAction`     | Wraps `RegisterProjectAction` with realpath + log-on-error.                    | Low      |
| `OpenRepositoryDialogAction`    | Wraps NativePHP `Dialog`; pure UI plumbing.                                    | Skip     |
| `ScanDirectoryDialogAction`     | Same — NativePHP `Dialog` wrapper.                                             | Skip     |

The two `*DialogAction` classes call `Native\Desktop\Dialog` and return
nothing exercisable in a headless test runner — leaving them untested is
intentional.

### 2. Untested foundational service

`GitProcessService` is the single chokepoint for every git invocation.
Today it is only exercised transitively. A focused unit test catches
regressions in:

- successful command output is returned verbatim,
- non-zero exit raises `GitCommandException` with `command`, `stderr`,
  `exitCode` populated,
- `core.quotepath=false` is in effect (unicode paths come through unquoted).

### 3. Untested console commands

| Command                       | Notes                                                                                |
|-------------------------------|--------------------------------------------------------------------------------------|
| `RegisterProjectCommand`      | Two-line happy/error paths over `RegisterProjectAction`. Easy `$this->artisan(...)`. |
| `ScanDirectoryCommand`        | Wraps `ScanDirectoryAction`; mostly formatter logic.                                 |

### 4. Livewire components without a dedicated PHP test

Single-file components ship with `extends Component` blocks that aren't
unit-tested:

- `⚡branch-explorer` — non-trivial: branch list refresh, commit history
  pagination, search debounce, escape handling. Covered indirectly by
  several browser tests, but a `Livewire::test()` would lock down the
  state transitions.
- `⚡theme-switcher` — empty `new class extends Component {}`; not worth a
  test.
- `keepalive`, `add-project-menu` — small surfaces.

`submit-bar` and `undo-toast` are pure Blade + Alpine views (no `extends
Component`), so they don't take a `Livewire::test()` suite — the browser
suite is the right place for them.

### 5. Model coverage

`Project`, `ReviewSession`, `Comment`, `ReviewedFile`, and `TrashedFile`
have factories and per-feature scope tests
(`tests/Unit/Models/CommentScopeTest.php`,
`tests/Unit/Models/ReviewedFileScopeTest.php`,
`tests/Unit/Observers/ProjectObserverTest.php`). They do **not** have
dedicated cast/relationship/fillable tests. Per the architecture
guidelines models are intentionally minimal, so the marginal value of
those tests is low — flag if a future schema change loses a cast
silently.

### 6. Services with thin coverage

| Service              | Tests | Suggested additions                                                                       |
|----------------------|-------|-------------------------------------------------------------------------------------------|
| `MarkdownFormatter`  | 7     | unicode/emoji bodies, fenced code blocks inside comments, nested list-in-quote, empty.    |
| `CommentExporter`    | 7     | inline code snippets, multi-file grouping, escaping of quote/backslash, empty body rows.  |

These are nice-to-haves, not blockers.

---

## Recommended Priority Order

1. `GitProcessService` — foundational, error formatting is the ROI.
2. `ResolveCommitAction` — small, pins the parent-selection contract.
3. `UpdateProjectSettingAction` — guarantees the allow-list filter.
4. `RegisterProjectCommand` — Artisan happy + error paths.
5. `GetFileCopyContentAction` — leaf `match`, easy lock-in.
6. `OpenProjectFromPathAction` — realpath + log-and-return-null branch.
7. `⚡branch-explorer` Livewire test — biggest remaining unit-test surface.
8. `MarkdownFormatter` / `CommentExporter` edge cases.

This refresh adds tests for items **1–4** under `tests/Unit/Services/` and
`tests/Unit/Actions/` and `tests/Unit/Console/`. Items 5+ remain backlog.

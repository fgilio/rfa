# Test Coverage Analysis

## Current State

The project has a solid test foundation with **~370+ test cases** across three suites:

| Suite | Files | Approx. Test Cases |
|-------|-------|--------------------|
| Unit (Actions) | 21 | ~130 |
| Unit (DTOs/Services/Other) | 15 | ~185 |
| Unit (Livewire) | 3 | ~69 |
| Arch | 11 | structural rules |
| Browser (Playwright) | 14 | UI integration |
| Performance | 1 | benchmark smoke |

**Well-tested areas:**
- `GitDiffService` (35 tests), `GitMetadataService` (54 tests), `ReviewPage` (43 tests) — deep coverage with edge cases
- `DiffParser` (16 tests), `SyntaxHighlightService` (15 tests) — good coverage of parsing logic
- `LoadFileDiffAction` (14 tests) — covers caching, syntax highlighting, errors, and context lines
- Most Actions have corresponding tests with happy path + basic edge cases
- Arch tests enforce layer boundaries, naming conventions, and no external resources

---

## Coverage Gaps

### 1. Untested Actions (4 missing test files)

| Action | Complexity | Priority |
|--------|-----------|----------|
| **`GetProjectStatusAction`** | Medium — error handling branch + aggregation logic | **High** |
| **`ListProjectsAction`** | High — raw SQL subqueries, sorting modes, date logic, grouping | **High** |
| **`ResolveCommitAction`** | Low — delegates to `GitMetadataService`, null handling | Medium |
| **`UpdateProjectSettingAction`** | Low — single Eloquent call | Low |

**`ListProjectsAction`** is the highest-priority gap: it contains raw SQL (`JSON_ARRAY_LENGTH`), two sort modes (`recent` vs `alpha`), Carbon date comparisons, and `groupBy` logic — all untested.

**`GetProjectStatusAction`** has a `try/catch` that silently swallows `GitCommandException` and returns a default array — this error path should be verified.

### 2. Untested Console Commands (2 files)

| Command | Notes |
|---------|-------|
| **`RegisterProjectCommand`** | Has success/failure paths, delegates to `RegisterProjectAction` |
| **`BenchmarkPerformanceCommand`** | Complex — but may be acceptable to skip as a dev tool |

`RegisterProjectCommand` is a simple Artisan command but its error-handling path (catching `RuntimeException`) is untested. Laravel makes command testing straightforward with `$this->artisan()`.

### 3. Untested Service: `GitProcessService`

This is the **foundational git execution layer** — every git operation flows through it. Currently untested. Key scenarios:

- Successful command execution returns stdout
- Failed command throws `GitCommandException` with correct `command`, `stderr`, `exitCode`
- Timeout behavior (30s configured)
- `core.quotepath=false` flag is passed correctly

While higher-level services test git indirectly via real repos, a focused unit test would catch regressions in error formatting and process configuration.

### 4. Thin Test Coverage on Existing Files

Several tested files have minimal test depth:

| File | Tests | Concern |
|------|-------|---------|
| `ExportReviewAction` | 3 | No test for multi-file reviews, special characters in comments, or markdown formatting edge cases |
| `ResolveProjectAction` | 3 | Only basic resolution — no test for missing project, stale paths |
| `HunkTest` | 3 | Hunk DTO has minimal structural tests |
| `CommitEntryTest` | 2 | Minimal DTO coverage |
| `SaveSessionAction` | 4 | No test for concurrent session updates or large comment payloads |
| `DeleteCommentAction` | 4 | No test for deleting non-existent comment |

### 5. Model Tests

Neither `Project` nor `ReviewSession` has dedicated unit tests. While models are "minimal" per architecture guidelines, they likely have:

- Casts, accessors, or mutators
- Fillable/guarded definitions
- Relationships

A quick test verifying mass-assignment protection and any casts would prevent silent breakage.

### 6. Livewire Component Coverage Gaps

Only 3 of the ~6 Livewire components have unit tests:

| Component | Tested? |
|-----------|---------|
| `ReviewPage` | Yes (43 tests — excellent) |
| `DashboardPage` | Yes (16 tests) |
| `DiffFile` | Yes (10 tests) |
| `BranchExplorer` | **No** |
| `UpdateChecker` | **No** |
| `ThemeSwitcher` | **No** |
| `SubmitBar` | **No** |
| `UndoToast` | **No** |

While some of these are simple, `BranchExplorer` and `SubmitBar` likely contain enough interaction logic to warrant tests.

### 7. `MarkdownFormatter` Edge Cases

Currently has 7 tests, but as a text-processing service, it's vulnerable to edge cases:

- Unicode/emoji in comments
- Code blocks within comments
- Nested markdown (lists inside quotes)
- Very long single-line content
- Empty/whitespace-only input

### 8. `CommentExporter` Service

Has 7 tests but could benefit from:

- Export with inline code snippets
- Multi-file grouped comments
- Comments with special characters (quotes, backslashes)
- Empty comment body handling

---

## Recommended Priority Order

1. **`ListProjectsAction`** — Most complex untested code; SQL + date logic + sorting
2. **`GetProjectStatusAction`** — Error swallowing behavior needs verification
3. **`GitProcessService`** — Foundational layer; unit test error formatting
4. **`RegisterProjectCommand`** — Quick win; Artisan command testing is simple
5. **`ResolveCommitAction`** — Null-return edge case
6. **Livewire: `BranchExplorer`, `SubmitBar`** — Interactive components with logic
7. **Deepen `ExportReviewAction`** — Multi-file and special character scenarios
8. **Model tests for `Project` / `ReviewSession`** — Guard casts and fillable
9. **`MarkdownFormatter` edge cases** — Unicode, nested markdown, empty input
10. **`UpdateProjectSettingAction`** — Low priority; trivial single-line action

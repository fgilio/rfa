# Architecture Audit: RFA (Review For Agent)

## Executive Summary

RFA is a well-structured local code review tool built on Laravel 12 + Livewire 4. It follows a clean layered architecture (Actions → Services → DTOs → Models) with good separation of concerns. The codebase is small (~25 source files), focused, and consistent. This audit identifies several areas for improvement ranging from structural refinements to potential bugs.

---

## 1. Architecture Overview

### Layered Design

```
┌────────────────────────────────────────────────┐
│  Blade/Alpine.js   (View layer)                │
│  ┌──────────────────────────────────────────┐  │
│  │  Livewire Components  (Thin adapters)    │  │
│  │  ┌──────────────────────────────────┐    │  │
│  │  │  Actions  (Business logic)       │    │  │
│  │  │  ┌──────────────────────────┐    │    │  │
│  │  │  │  Services  (Domain ops)  │    │    │  │
│  │  │  └──────────────────────────┘    │    │  │
│  │  │  ┌──────────────────────────┐    │    │  │
│  │  │  │  DTOs  (Data containers) │    │    │  │
│  │  │  └──────────────────────────┘    │    │  │
│  │  └──────────────────────────────────┘    │  │
│  └──────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────┐  │
│  │  Model (ReviewSession) → SQLite          │  │
│  └──────────────────────────────────────────┘  │
└────────────────────────────────────────────────┘
```

**Verdict**: The layering is clean and enforced by convention. Actions depend on Services/DTOs, Livewire depends on Actions, and the Model layer is minimal. There are no circular dependencies.

---

## 2. Strengths

### 2.1 Clear Responsibility Boundaries
Each layer has a well-defined role:
- **Actions**: Single use-case, `final readonly class` with `handle()` — callable from any interface (web, CLI, test).
- **Services**: Stateless domain operations (git, parsing, formatting, ignore patterns).
- **DTOs**: Immutable value objects with `readonly` properties and serialization methods.
- **Livewire**: Thin UI adapters that delegate all logic to Actions.

### 2.2 Testability
- Actions are pure functions in most cases (accept data, return data), making them trivially testable.
- Services are injected via constructor, easily mockable.
- Comprehensive test suite covering Actions, Services, and DTOs with Pest.

### 2.3 Simplicity
- Single route, single page app — appropriate for the tool's scope.
- No over-engineering: no repository pattern, no interfaces for things that have a single implementation, no event sourcing.
- SQLite for local persistence is the right choice for a local dev tool.

### 2.4 Lazy Loading Diffs
- `DiffFile` uses `x-intersect` to lazy-load diffs only when scrolled into view (`DiffFile.php:44-55`), preventing expensive git operations on mount for large changesets.

### 2.5 Session Persistence
- Review state (comments, viewed files, global comment) survives page refreshes via `ReviewSession` model, with automatic pruning of stale entries for files no longer in the diff (`RestoreSessionAction.php:22-34`).

---

## 3. Issues & Recommendations

### 3.1 [High] Inconsistent Data Representation — Arrays vs DTOs

**Problem**: The application defines proper DTOs (`Comment`, `FileDiff`, `FileListEntry`, etc.) but the Livewire layer and Actions mostly work with raw `array<string, mixed>` instead. Data flows as:

1. `GitDiffService` returns `FileListEntry[]` DTOs
2. `GetFileListAction` immediately converts them to arrays via `toArray()`
3. All subsequent code (`ReviewPage`, `AddCommentAction`, `BuildDiffContextAction`, etc.) operates on `array<string, mixed>`

Similarly, `AddCommentAction` returns a raw array instead of a `Comment` DTO, and `ExportReviewAction` must reconstruct `Comment` DTOs from arrays at export time (`ExportReviewAction.php:24-31`).

**Impact**: Loss of type safety across the entire business logic layer. Every `$comment['fileId']` or `$file['path']` access is untyped and unchecked. PHPStan at level 6 cannot validate these accesses.

**Recommendation**: Either:
- (a) Keep DTOs as the canonical data format through the Action layer and only serialize to arrays at the Livewire boundary, or
- (b) Accept the array approach but create typed factory methods or value objects for the array shapes.

Option (a) would eliminate the DTO→array→DTO round-trip in `ExportReviewAction` and make the code safer.

### 3.2 [High] `DiffCacheKey` Is Misplaced

**Problem**: `DiffCacheKey` lives in `app/Actions/` (`DiffCacheKey.php`) but it's a static utility, not an Action. It doesn't follow the `final readonly class` + `handle()` convention. It's used by both `DiffFile` (Livewire) and `GetFileListAction`/`BuildDiffContextAction` (Actions).

**Recommendation**: Move to `app/Services/` or a dedicated `app/Support/` namespace, since it's a shared utility.

### 3.3 [Medium] Silent Git Failures

**Problem**: `GitDiffService::runGit()` returns an empty string on failure (`GitDiffService.php:232-234`):

```php
if (! $process->isSuccessful()) {
    return '';
}
```

This makes it impossible to distinguish between "no output" and "command failed." If `git diff HEAD` fails (e.g., no commits yet, corrupt repo), the app silently shows no files rather than reporting the error.

**Recommendation**: Either throw an exception for unexpected failures, or return a result object that distinguishes success-with-empty-output from failure. At minimum, log the stderr output for debugging.

### 3.4 [Medium] No Input Sanitization on `ResolveRepoPathAction`

**Problem**: The repo path comes from three sources (`ResolveRepoPathAction.php:13-23`):
1. `$_ENV['RFA_REPO_PATH']` — environment variable
2. `.rfa_repo_path` file content
3. `getcwd()`

None of these are validated to be an actual directory or a git repository. A bad path silently propagates and causes git commands to fail with empty output (see 3.3).

**Recommendation**: Add validation that the resolved path is a directory and contains a `.git` directory (or is inside a git worktree). Fail fast with a clear error message.

### 3.5 [Medium] `$_ENV` Usage Instead of Laravel's `env()`

**Problem**: `ResolveRepoPathAction.php:13` reads `$_ENV['RFA_REPO_PATH']` directly instead of using Laravel's `env()` helper or config binding.

**Recommendation**: Bind `RFA_REPO_PATH` through `config/rfa.php` (e.g., `'repo_path' => env('RFA_REPO_PATH')`), then read via `config('rfa.repo_path')`. This is more consistent with the rest of the Laravel app and allows the value to be cached with `config:cache`.

### 3.6 [Medium] Tailwind CDN in Production

**Problem**: The layout (`app.blade.php:8`) loads Tailwind via CDN:
```html
<script src="https://cdn.tailwindcss.com"></script>
```

The Tailwind CDN is explicitly not recommended for production use — it's a development-only tool. It's ~300KB of JavaScript that generates styles at runtime in the browser.

**Impact**: Slower initial render, flash of unstyled content, and dependency on an external CDN for a local tool. The Tailwind config is also inlined as JavaScript in the `<head>`, duplicating information from the PHP `config/theme.php`.

**Recommendation**: For a local dev tool this is somewhat acceptable given the simpler build pipeline, but a proper Tailwind CSS build (via Vite) would produce a small, static CSS file and remove the CDN dependency. If keeping CDN, this is a pragmatic tradeoff to document.

### 3.7 [Medium] Unbounded Diff Data in Livewire State

**Problem**: `DiffFile` stores parsed diff data in `$diffData` and caches it via Laravel's cache. However, when Livewire renders components for all files in the review, and users expand many files, the full diff data for every expanded file is held in cache with a 24-hour TTL.

The diff data is stored as the `protected ?array $diffData` property and re-hydrated from cache on every Livewire request cycle (`DiffFile.php:31-42`). For repositories with many large files, this could lead to significant cache bloat.

**Recommendation**: Consider an LRU eviction strategy or a shorter TTL. The `cache_ttl_hours: 24` default in `config/rfa.php` seems long for a tool that reviews current uncommitted changes — diffs become stale as soon as the working tree changes.

### 3.8 [Low] Comment ID Generation Uses `uniqid()`

**Problem**: `AddCommentAction.php:25` generates comment IDs with:
```php
'id' => 'c-'.uniqid()
```

`uniqid()` is based on the current time in microseconds and is not guaranteed to be unique under concurrent requests.

**Impact**: Low for a single-user local tool, but still a code smell. If two comments are added in rapid succession (e.g., automated testing), there's a theoretical collision risk.

**Recommendation**: Use `Str::uuid()` or `Str::ulid()` for guaranteed uniqueness.

### 3.9 [Low] Export Hash Includes `time()` — Non-Deterministic

**Problem**: `CommentExporter.php:23`:
```php
$hash = substr(md5(json_encode($comments).$globalComment.time()), 0, 8);
```

Including `time()` means the same review exported twice in different seconds produces different filenames, making the output non-deterministic and harder to test.

**Recommendation**: Remove `time()` from the hash — the `$now` timestamp in the filename already provides uniqueness. Or use a deterministic hash of the content only.

### 3.10 [Low] Missing `declare(strict_types=1)` in Several Files

**Problem**: Inconsistent use of strict types across the codebase:
- **Has it**: All Actions, `CommentExporter`, `MarkdownFormatter`, all DTOs
- **Missing**: `GitDiffService`, `IgnoreService`, `DiffParser`, `ReviewSession`, `ReviewPage`, `DiffFile`, `AppServiceProvider`

**Recommendation**: Add `declare(strict_types=1)` to all PHP files for consistency and to catch type coercion bugs early.

### 3.11 [Low] `ReviewPage` Uses Service Locator Pattern

**Problem**: `ReviewPage` resolves Actions via `app(SomeAction::class)` throughout (`ReviewPage.php:42-44, 54, 67, 81, 100, 123`):
```php
$this->repoPath = app(ResolveRepoPathAction::class)->handle();
```

While this works, it's the service locator anti-pattern — dependencies are hidden rather than declared in the constructor.

**Recommendation**: Use Livewire's constructor injection or `mount()` injection to make dependencies explicit. This would also make the component easier to unit test.

### 3.12 [Low] `DeleteCommentAction` Prefix Check is Brittle

**Problem**: `DeleteCommentAction.php:15`:
```php
if (! str_starts_with($commentId, 'c-')) {
    return null;
}
```

This validation couples the action to a specific ID format that is generated elsewhere (`AddCommentAction`). If the format changes, this breaks silently.

**Recommendation**: Either remove this check (the filter logic handles non-matching IDs naturally) or centralize the ID format as a constant/method.

---

## 4. Data Flow Analysis

### Happy Path: Page Load
```
Browser → GET /
  → ReviewPage::mount()
    → ResolveRepoPathAction → repo path
    → GetFileListAction
      → GitDiffService::getFileList() → FileListEntry[]
      → FileListEntry::toArray() → array[]
    → RestoreSessionAction
      → ReviewSession::firstOrCreate()
      → Prune stale comments/viewed files
  → render review-page.blade.php
    → For each file: <livewire:diff-file />
      → DiffFile (no diff loaded yet)
```

### Happy Path: Expand a File
```
Browser scrolls file into view → x-intersect fires
  → $wire.loadFileDiff()
    → DiffFile::loadFileDiff()
      → Cache::remember()
        → LoadFileDiffAction
          → GitDiffService::getFileDiff() → raw diff string
          → DiffParser::parseSingle() → FileDiff DTO
          → FileDiff::toViewArray() → array
```

### Happy Path: Add Comment
```
Alpine.js → selectLine() → saveDraft()
  → $wire.dispatch('add-comment', {...})
    → ReviewPage::addComment()
      → AddCommentAction::handle() → array|null
      → $this->comments[] = $comment
      → SaveSessionAction → ReviewSession::updateOrCreate()
```

### Happy Path: Submit Review
```
ReviewPage::submitReview()
  → SaveSessionAction (persist current state)
  → ExportReviewAction
    → Reconstruct Comment DTOs from arrays
    → BuildDiffContextAction (extract diff snippets)
    → CommentExporter::export()
      → MarkdownFormatter::format() → markdown string
      → Storage::build() → write .json + .md to .rfa/
  → Dispatch copy-to-clipboard event
```

**Observation**: The DTO→array→DTO round-trip in the submit flow is the most visible consequence of issue 3.1.

---

## 5. Dependency Graph

```
ReviewPage (Livewire)
├── ResolveRepoPathAction
├── GetFileListAction
│   └── GitDiffService
│       └── IgnoreService
├── RestoreSessionAction
│   └── ReviewSession (Model)
├── AddCommentAction
├── DeleteCommentAction
├── ToggleViewedAction
├── SaveSessionAction
│   └── ReviewSession (Model)
└── ExportReviewAction
    ├── BuildDiffContextAction
    │   └── LoadFileDiffAction
    │       ├── GitDiffService
    │       └── DiffParser
    └── CommentExporter
        └── MarkdownFormatter

DiffFile (Livewire)
├── DiffCacheKey (utility)
└── LoadFileDiffAction
    ├── GitDiffService
    └── DiffParser
```

No circular dependencies. The graph is a clean DAG.

---

## 6. Security Considerations

### 6.1 Git Command Injection — Mitigated
`GitDiffService::runGit()` uses `Symfony\Component\Process` with array arguments (`GitDiffService.php:228`), which avoids shell injection. File paths are passed as separate array elements, not interpolated into a shell string. This is correct.

### 6.2 Path Traversal — Low Risk
The tool is designed for local use. However, `ResolveRepoPathAction` trusts the env variable and file content without sanitization. Since this is a local developer tool (not internet-facing), the risk is minimal.

### 6.3 XSS in Comments — Mitigated
Blade's `{{ }}` syntax auto-escapes output. Comment bodies rendered in `diff-file.blade.php:238` use `{{ $comment['body'] }}`, which is safe. The `x-model` / `x-text` Alpine.js directives also auto-escape.

### 6.4 Clipboard Write — Acceptable
The `copy-to-clipboard` event (`review-page.blade.php:19`) calls `navigator.clipboard.writeText()`, which requires a secure context but is otherwise safe.

---

## 7. Performance Considerations

### 7.1 N+1 on File Comments
`ReviewPage::getFileComments()` (`ReviewPage.php:111-113`) is called once per file in the render loop. Each call runs `groupedComments()` which re-groups all comments every time. For N files, this groups comments N times.

**Recommendation**: Cache the grouped result in a component property and invalidate it when comments change.

### 7.2 Repeated `collect()` Calls in Loops
`BuildDiffContextAction.php:30` calls `collect($files)->firstWhere('id', ...)` inside a loop over comments. This creates a new Collection on every iteration.

**Recommendation**: Build a lookup map (`$fileById`) before the loop.

### 7.3 Full Untracked File Read for Line Counting
`GitDiffService::getFileList()` reads the full content of every untracked file (`GitDiffService.php:135-136`) just to count lines. For large untracked files, this is wasteful.

**Recommendation**: Use `SplFileObject` or shell `wc -l` for line counting without loading entire file contents into memory.

---

## 8. Test Coverage Assessment

The test suite covers:
- All 10 Actions (unit tests)
- All 5 Services (unit tests)
- DTOs (unit tests for serialization)
- Browser/E2E tests via Playwright

**Gaps**:
- No integration test for the full page-load → add-comment → submit-review flow at the Livewire component level.
- `DiffFile` Livewire component has no dedicated component test (only tested indirectly via browser tests).

---

## 9. Summary of Recommendations (by priority)

| # | Priority | Issue | Effort |
|---|----------|-------|--------|
| 3.1 | High | Inconsistent array vs DTO usage | Medium |
| 3.2 | High | `DiffCacheKey` misplaced in Actions | Low |
| 3.3 | Medium | Silent git failures | Low |
| 3.4 | Medium | No repo path validation | Low |
| 3.5 | Medium | `$_ENV` instead of config | Low |
| 3.6 | Medium | Tailwind CDN usage | Medium |
| 3.7 | Medium | Cache TTL too long for volatile diffs | Low |
| 7.1 | Medium | N+1 comment grouping in render | Low |
| 7.2 | Medium | Repeated `collect()` in loop | Low |
| 3.8 | Low | `uniqid()` for comment IDs | Low |
| 3.9 | Low | Non-deterministic export hash | Low |
| 3.10 | Low | Missing `strict_types` declarations | Low |
| 3.11 | Low | Service locator in `ReviewPage` | Low |
| 3.12 | Low | Brittle comment ID prefix check | Low |
| 7.3 | Low | Full file read for line counting | Low |

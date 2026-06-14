# Hunk Lessons Implementation Plan

## Purpose

This plan translates the useful architectural lessons from `modem-dev/hunk` into RFA.

The goal is not to copy Hunk's terminal UI or agent daemon. The goal is to strengthen RFA's review engine:

- safer Git usage
- one normalized review model
- stronger patch parsing
- lazy source loading
- predictable state derivation
- better limits for large and binary diffs
- reusable review snapshots

## Explicit Non-Goals

- Do not add a loopback daemon.
- Do not add live agent command/control.
- Do not add a separate session broker.
- Do not rebuild RFA as a CLI-first app.
- Do not replace Livewire with a custom renderer.
- Do not add new dependencies without a separate approval.

## Main Architectural Lesson

Hunk's strongest design choice is not the CLI. It is the pipeline:

1. Parse user/runtime input.
2. Ask VCS adapters for raw patch/source data.
3. Normalize all inputs into one internal changeset model.
4. Derive review state from that model.
5. Let the UI render derived state instead of owning domain logic.

RFA should move toward the same shape while staying Laravel-native:

- Actions stay as use-case entry points.
- Services own Git, patch parsing, source fetching, and state derivation.
- DTOs are the canonical boundary between services, actions, UI, exports, and persistence.
- Livewire components stay thin.

## Proposed Implementation Order

1. Normalized review model
2. Git adapter hardening
3. Patch normalization pipeline
4. Lazy source fetcher
5. Review state derivation
6. Large file and binary safety
7. Path bounds
8. Config layering
9. Moved-line support
10. Static/export renderer reuse
11. Review snapshot import/export

This order avoids building advanced features on top of unstable raw diff arrays.

## 1. Normalized Review Model

### Goal

Every review source becomes one internal shape before the UI sees it.

### Existing DTOs To Keep

RFA already has most of the diff model:

- `FileDiff`
- `Hunk`
- `DiffLine`
- `FileListEntry`
- `DiffTarget`
- `LineType`

Do not add parallel `ReviewFile`, `ReviewHunk`, or `ReviewLine` DTOs just because Hunk has different names. Evolve the existing DTOs unless there is a concrete missing concept.

A hunk is Git's term for one contiguous block inside a unified diff. It starts with a header like `@@ -10,7 +10,9 @@` and contains the changed plus surrounding context lines for that block. RFA already models this as `App\DTOs\Hunk`.

### Actual Gaps

- `ReviewChangeset` - one top-level object that groups the repo/source label, ordered file list, warnings, skipped files, and current `DiffTarget`.
- source specs for old/new content - likely added to `FileDiff` or a small `FileSourceSpec` DTO.
- stable hunk ids - likely added to `Hunk`.
- file status as a PHP enum - optional, because statuses are currently strings.
- moved-line marker - optional, only if color-moved support ships.

### Responsibilities

`ReviewChangeset` should contain:

- repository root
- source label
- ordered files
- optional warnings
- optional skipped files

`FileDiff` or the file-level view model should contain:

- stable file id
- old path
- new path
- display path
- status
- binary flag
- too-large flag
- hunks
- source specs for old/new content

`Hunk` should contain:

- stable hunk id
- old start/count
- new start/count
- lines

`DiffLine` should contain:

- old line number
- new line number
- content
- line type
- optional moved-line marker

### RFA Fit

This belongs in `app/DTOs/`.

Actions should return DTOs where possible. Livewire can serialize DTOs at the boundary, but core actions should avoid DTO to array to DTO round-trips.

### Implementation Steps

1. Add only the missing top-level `ReviewChangeset` DTO.
2. Extend existing `FileDiff`, `Hunk`, and `DiffLine` where the plan identifies real gaps.
3. Add a file-status enum only if it removes meaningful string switching.
4. Update existing diff-loading actions to return the canonical model internally.
5. Keep compatibility adapters temporarily because current Livewire components expect arrays.
6. Remove raw array access from domain actions after UI migration.

### Tests

- Existing DTO serialization round-trip.
- `ReviewChangeset` groups existing file DTOs without duplicating them.
- Stable file ids across equivalent inputs.
- Stable hunk ids across equivalent inputs.
- Added, modified, deleted, renamed, binary, and untracked files.

### Risks

- Livewire serialization may push us toward arrays at the component boundary.
- The safe compromise is DTOs in Actions/Services, arrays only inside component public state.

## 2. Git Adapter Hardening

### Goal

Make Git output stable, explicit, and isolated from UI behavior.

### Hunk Lesson

Hunk forces parseable Git output instead of trusting user config:

- disables external diff tools
- forces predictable prefixes
- controls color use
- maps Git errors to meaningful domain errors
- treats untracked files separately

### RFA Fit

This belongs in `app/Services/GitDiffService.php` or a new narrower service if the current class is too broad.

### Implementation Steps

1. Keep `GitProcessService` as the single command runner. It already throws `GitCommandException` with stderr and exit code.
2. Add default diff flags where they are missing:
   - `--no-ext-diff`
   - `--find-renames`
   - `--no-color` unless moved-line parsing is explicitly enabled
   - `--src-prefix=a/`
   - `--dst-prefix=b/`
3. Use `git -c` overrides when needed to neutralize user config that affects parsing.
4. Add a richer command result only if callers need stdout plus metadata without exceptions.
5. Translate common failures above the raw `GitCommandException` when the UI can act differently:
   - Git missing
   - not a Git repository
   - bad revision
   - empty repository
   - pathspec not found
6. Make untracked handling explicit.

### Tests

- Command construction includes stable flags.
- Empty diff is distinct from failed Git command.
- Bad revision returns a useful domain error.
- User Git config cannot change patch prefixes.
- Untracked files appear in predictable order.

### Risks

- Some existing flows may rely on "empty output means no files".
- The migration should first introduce structured errors, then update UI copy.

## 3. Patch Normalization Pipeline

### Goal

Normalize raw patches before parsing or rendering.

### Hunk Lesson

Hunk treats patch normalization as a dedicated pipeline:

- strip unsafe terminal/control sequences only for external/imported patches or future color-moved support
- normalize Git headers
- normalize no-prefix and mnemonic-prefix diffs
- preserve only meaningful metadata
- split multi-file patches into file chunks

### RFA Fit

Add `app/Services/PatchNormalizer.php` and only split out collaborators when the code justifies it:

- `GitPatchHeaderNormalizer`
- `PatchChunkSplitter`

Do not add a standalone `TerminalTextSanitizer` for the current Git path. RFA already runs local Git with `--no-color` for file diffs, and `GitProcessService` does not go through a shell. Sanitization only makes sense when RFA accepts raw external patch text, preserves Git color-moved markers, or displays text that did not come from RFA-controlled Git commands.

### Implementation Steps

1. Add `PatchNormalizer::normalize(string $patch): NormalizedPatch`.
2. Keep current RFA-controlled Git output plain. Add narrow control-sequence stripping only when supporting imported patches or color-moved parsing.
3. Normalize `diff --git` headers.
4. Normalize `---` and `+++` paths.
5. Preserve rename, copy, mode, deletion, binary, and similarity metadata.
6. Split multi-file patches into chunks.
7. Store normalized raw patch text on the model only where useful for export/debugging.

### Tests

Use fixtures for:

- normal modified file
- added file
- deleted file
- renamed file
- copied file
- binary file
- file with spaces
- quoted paths
- no-prefix diff
- mnemonic-prefix diff
- patch with terminal color
- patch generated by `git show`
- multi-commit patch with commit headers

### Risks

- Patch parsing bugs are high-impact because all review views depend on this layer.
- Build with fixtures before changing UI.

## 4. Lazy Source Fetcher

### Goal

Fetch old/new source text only when needed.

### Hunk Lesson

Hunk stores source specs in the model and fetches text lazily for expanded context.

### RFA Fit

RFA already has `GitFileContentService` for content reads at a ref, the working copy, and external paths. Do not add a competing content service first.

Add only the missing model around it:

- `FileSourceSpec`
- `SourceText`
- `SourceTextTooLarge`

### Supported Sources

- filesystem path
- Git blob at revision
- Git index
- none

### Implementation Steps

1. Add source specs to `FileDiff` or the file-level view model.
2. Build source specs during Git diff loading.
3. Use `GitFileContentService` as the fetcher.
4. Add byte limits before loading source content.
5. Reuse the service's request-scoped cache where possible.
6. Return explicit missing/too-large states instead of throwing through Livewire.
7. Use source specs for expanded context, exports, and future file previews.

### Tests

- Fetch old content from Git blob.
- Fetch new content from working tree.
- Fetch staged content from index.
- Missing file returns a controlled empty result.
- Large file returns a controlled too-large result.

### Risks

- Git index reads can be subtle for staged versus unstaged views.
- Keep source specs explicit so tests can cover each side.

## 5. Review State Derivation

### Goal

Move derived review state out of Livewire components.

### Hunk Lesson

Hunk uses pure derivation for visible files, cursor state, filters, navigation targets, counters, and empty states.

### RFA Fit

Add `ReviewStateService` or immutable `ReviewState` DTO in `app/Services/`.

### Inputs

- `ReviewChangeset`
- selected file id
- selected hunk id
- file filter
- viewed files
- collapsed files
- comment state

### Outputs

- visible files
- selected file
- selected hunk
- next/previous navigation targets
- counts by status
- reviewed/unreviewed counts
- empty state reason

### Implementation Steps

1. Identify derived state currently calculated in Livewire.
2. Move one derived concern at a time into pure methods.
3. Keep Livewire public properties as inputs.
4. Let rendering consume derived DTOs.
5. Add tests for filter, selection, and navigation behavior.

### Tests

- selected file remains valid after filtering.
- next/previous hunk navigation skips hidden files.
- empty changeset returns correct empty state.
- viewed/commented counts are deterministic.

### Risks

- This can grow into a large service.
- Prefer small methods with DTO inputs over a stateful manager.

## 6. Large File And Binary Safety

### Goal

Prevent expensive diffs from freezing the desktop app.

### Hunk Lesson

Hunk checks sizes and binary status before deep parsing.

### RFA Fit

Extend the existing limit path near Git loading and patch parsing, not in the UI. RFA already has `config('rfa.diff_max_bytes')`, `GitDiffService` max-byte checks, and `FileDiff::emptyArray(..., tooLarge: true)`.

### Proposed Limits

Exact values should be decided after measuring current RFA behavior, but the controls should exist:

- max patch bytes per file
- max source bytes per file
- max rendered diff lines per file
- max total files per review before degraded mode

### Implementation Steps

1. Extend existing `config('rfa.diff_max_bytes')` instead of adding a separate limit path.
2. Keep binary detection in `GitDiffService` and `DiffParser`, but make skipped reasons explicit.
3. Detect oversized patches before parsing into full line arrays.
4. Represent skipped files through `FileDiff::emptyArray()` or a typed replacement that preserves `tooLarge`.
5. Surface skipped reason in UI.
6. Allow export to mention skipped files instead of silently omitting them.

### Tests

- binary file is marked binary and not parsed as text.
- oversized file becomes skipped.
- skipped file still appears in file list.
- export includes skipped-file reason.

### Risks

- Users may want to force-open a large file.
- Add the safe model first; force-open can be a later explicit feature.

## 7. Safe Local Path Bounds

### Goal

Prevent accidental reads outside the intended repository roots.

### Hunk Lesson

Even without a daemon, Hunk's reload bounds are valuable: realpath roots, symlink checks, and rejecting path escape.

### RFA Fit

RFA already has `App\Support\PathGuard`, with relative-path checks and symlink-escape protection for writes/restores. Extend or reuse that first.

Use it for repo-relative reads where the same path-escape concerns apply. Be careful with configured external paths: those intentionally live outside the repo and should use their own root bounds from `ExternalFilesService`.

### Implementation Steps

1. Audit repo-relative file reads and image serving for `PathGuard` coverage.
2. Reuse `PathGuard::assertWithinRepo()` where the file should stay inside the repository.
3. Keep external files bounded by their configured external root, not by repo root.
4. Resolve repository root with `realpath` where needed.
5. Treat missing paths carefully for deleted files.
6. Reject symlink escapes for read paths that follow filesystem content.
7. Apply to patch imports and future reloads.

### Tests

- normal in-repo file is allowed.
- `../` escape is rejected.
- symlink to outside repo is rejected.
- deleted file path can still be represented without reading from disk.
- repo root itself is allowed.

### Risks

- Git can represent deleted paths that no longer exist, so not every path can require `realpath`.
- The service should distinguish "path identity" from "readable filesystem path".

## 8. Config Layering

### Goal

Make review behavior configurable without scattering defaults.

### Hunk Lesson

Hunk has clear config precedence:

1. defaults
2. global config
3. repo config
4. command/runtime options

RFA does not need to copy the exact files, but it should centralize effective review config.

### RFA Fit

Use Laravel config plus DB-backed repo settings if needed.

### Proposed Layers

1. application defaults in `config/rfa.php`
2. local user preferences in SQLite
3. repo-specific settings in SQLite
4. runtime UI choices

Avoid adding `.rfa/config.toml` until there is a strong reason to keep settings inside each source repo.

### Implementation Steps

1. Define `ReviewConfig` DTO.
2. Move diff limits and parser options into config.
3. Add a resolver that returns effective config for a repo.
4. Validate config values at resolution time.
5. Keep config immutable once a review load starts.

### Tests

- defaults resolve with no user/repo settings.
- repo settings override defaults.
- runtime choices override repo settings.
- invalid limit values are rejected.

### Risks

- DB-backed settings may be easier for a desktop app than dotfiles.
- Do not add project config files until UX needs them.

## 9. Git Color-Moved Support

### Goal

Preserve Git's moved-line signal when it materially improves review.

### Hunk Lesson

Hunk uses Git's `--color-moved` output, reads moved-line ANSI markers, stores moved state, then strips styling.

### RFA Fit

This is useful, but it should come after patch normalization is strong.

### Implementation Steps

1. Add a config flag for moved-line detection, disabled by default.
2. Read Git's `diff.colorMoved` and `diff.colorMovedWS` only if we decide user Git config should influence the feature.
3. Run Git with `--color=always --color-moved=<mode>` when enabled.
4. Use dedicated colors for moved old/new lines.
5. Parse only those ANSI markers.
6. Strip all ANSI before storing line content.
7. Store moved-line status on `DiffLine`.

### Tests

- moved old/new lines are marked.
- non-moved colored output does not leak control codes.
- disabled mode produces plain patch parsing.
- user color config does not break parsing.

### Risks

- ANSI parsing can become brittle.
- Keep it optional until fixture coverage is broad.

## 10. Static And Export Renderer Reuse

### Goal

Use one parse model for interactive review and exported review context.

### Hunk Lesson

Hunk's static pager reuses the same loader, parser, and row-building logic as the interactive view.

### RFA Fit

RFA exports should consume `ReviewChangeset` and comments, not reconstruct from raw arrays.

### Implementation Steps

1. Make `ExportReviewAction` consume `ReviewChangeset`.
2. Add renderer-specific adapters:
   - Livewire view model
   - Markdown export model
   - plain text model if useful
3. Keep diff parsing out of renderers.
4. Snapshot-test exported markdown.

### Tests

- export includes same file ordering as UI.
- export includes comments attached to stable file/hunk ids.
- skipped files are represented.
- renamed files render correctly.

### Risks

- Current export may depend on array shapes from UI state.
- Migrate after the canonical DTO exists.

## 11. Review Snapshot Import/Export

### Goal

Take the generalizable part of Hunk's agent/session work without adding a daemon.

### What To Take

- serializable review snapshot DTO
- stable file ids
- stable hunk ids
- comments and notes as projections
- optional JSON import/export

### What To Skip

- local loopback server
- live window registration
- command bridge
- agent-issued navigation commands
- broker lifecycle
- websocket registration

### RFA Fit

This can become a file-based interchange format for:

- review persistence
- exporting context to agents manually
- importing generated notes
- debugging review state

### Implementation Steps

1. Define `ReviewSnapshot` DTO.
2. Include schema version.
3. Include repository root or display label, but avoid making absolute paths mandatory.
4. Include files, hunks, comments, viewed state, and global notes.
5. Add JSON export first.
6. Add JSON import only after validation rules are clear.

### Tests

- snapshot schema version is present.
- snapshot round-trips without losing comments.
- unknown future fields are ignored or rejected intentionally.
- invalid file ids are rejected.

### Risks

- Snapshot import can become a trust boundary.
- Start with export-only if there is no immediate import use case.

## Cross-Cutting Requirements

### Stable IDs

Stable ids should not depend on array index alone.

Suggested file id ingredients:

- repository identity or review source
- old path
- new path
- file status

Suggested hunk id ingredients:

- file id
- old start/count
- new start/count
- normalized hunk header

### Error Handling

Prefer domain errors over empty states:

- `GitCommandFailed`
- `RepositoryNotFound`
- `InvalidRevision`
- `PatchParseFailed`
- `SourceTextTooLarge`
- `PathOutsideRepository`

The UI can still render friendly empty/error states, but Actions should not collapse distinct failures into empty arrays.

### Test Fixture Strategy

Add focused fixtures for patch parsing instead of relying only on live Git repos.

Keep fixture names descriptive:

- `modified-file.patch`
- `renamed-file.patch`
- `binary-file.patch`
- `quoted-paths.patch`
- `colored-moved-lines.patch`
- `git-show-with-commit-header.patch`

### Performance Strategy

Prefer early limits:

- before full parsing
- before Livewire serialization
- before storing large arrays in cache
- before rendering repeated line components

### Migration Strategy

Use an adapter phase:

1. Add missing canonical DTOs and extend existing diff DTOs.
2. Convert existing action output into the canonical model.
3. Let current UI consume arrays generated from DTOs.
4. Move Livewire internals to DTO-aware derived state.
5. Remove old array shapes.

This avoids a risky full rewrite.

## Suggested Milestones

### Milestone 1 - Core Model

Deliver:

- `ReviewChangeset` plus needed extensions to existing diff DTOs
- basic Git diff loader outputs `ReviewChangeset`
- tests for common file statuses

Why first:

- every later feature depends on a stable model.

### Milestone 2 - Safer Git

Deliver:

- stable Git flags
- explicit Git error types
- untracked file handling cleanup

Why second:

- model quality depends on predictable Git input.

### Milestone 3 - Patch Pipeline

Deliver:

- patch normalizer
- fixture suite
- multi-file chunking
- imported-patch sanitization only if that input path ships

Why third:

- avoids putting parser quirks into UI/actions.

### Milestone 4 - Source Fetching And Limits

Deliver:

- source specs
- `GitFileContentService` integration
- byte limits
- binary and large-file states

Why fourth:

- keeps large repositories usable.

### Milestone 5 - Review State

Deliver:

- pure review state derivation
- navigation/filter tests
- thinner Livewire state handling

Why fifth:

- easier once the model is stable.

### Milestone 6 - Snapshots And Export Reuse

Deliver:

- review snapshot DTO
- JSON export
- markdown export consuming canonical model

Why sixth:

- useful for RFA's agent workflow without daemon complexity.

## What To Learn From Hunk, In One Sentence

Treat Git output as hostile input, normalize it into a stable domain model, derive all review state from that model, and keep the UI as a projection.

## Unresolved Questions

- Should repo-specific review settings live only in SQLite, or should RFA eventually support repo-local config files?
- Should moved-line detection follow user Git config, or be an RFA-only setting?
- Should review snapshots start export-only, or should import be part of the first version?

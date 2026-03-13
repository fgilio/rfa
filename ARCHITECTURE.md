# RFA Architecture Guide

> **RFA** — a local code review tool. You run `./rfa` inside a git repo, it starts a local web server, and opens a browser-based diff viewer with inline commenting, syntax highlighting, and session persistence.

---

## Level 0: What is RFA?

```
 ┌─────────────────────────────────────────────────────┐
 │                    YOUR TERMINAL                     │
 │                                                      │
 │   ~/my-project $ ./rfa                               │
 │   rfa daemon started (PID 1234) at http://127.0.0.1:4000  │
 │   http://127.0.0.1:4000/p/my-project                │
 │                                                      │
 └──────────────────────┬──────────────────────────────┘
                        │
                        ▼
 ┌─────────────────────────────────────────────────────┐
 │                   YOUR BROWSER                       │
 │                                                      │
 │  ┌─ Sidebar ──┐  ┌─ Main Panel ───────────────────┐ │
 │  │ file1.php  │  │  - file1.php                    │ │
 │  │ file2.js   │  │  @@ -10,3 +10,5 @@             │ │
 │  │ file3.css  │  │  - old line                     │ │
 │  │            │  │  + new line                     │ │
 │  │            │  │  💬 [Add comment...]             │ │
 │  └────────────┘  └─────────────────────────────────┘ │
 └─────────────────────────────────────────────────────┘
```

RFA is a **Laravel + Livewire** app that runs as a **local daemon**. It reads your git diffs, highlights syntax, and lets you write review comments — all offline, no GitHub needed.

---

## Level 1: The Shell Wrapper

The entry point `./rfa` is a bash script that manages the daemon lifecycle:

```
./rfa [command]
  │
  ├── (no args)  →  Register repo + start daemon + open browser
  ├── stop       →  Kill the daemon process
  ├── status     →  Show running projects
  ├── dump       →  Export SQLite DB to CSV
  └── flush      →  Clear all data
```

**Default flow** (no args):

```
./rfa
  │
  ├─ 1. Validate: is this a git repo?
  │
  ├─ 2. Is daemon alive?
  │     ├─ NO  → find free port (starting 4000)
  │     │        → bootstrap .env if missing
  │     │        → run migrations
  │     │        → nohup php artisan serve
  │     │        → wait for readiness (up to 10s)
  │     └─ YES → skip
  │
  ├─ 3. Register project:
  │     php artisan rfa:register $(pwd)
  │     → returns slug (e.g. "my-project")
  │
  └─ 4. Open browser:
        http://127.0.0.1:{port}/p/{slug}
```

---

## Level 2: Application Layers

RFA follows a clean layered architecture:

```
┌─────────────────────────────────────────────────────────────────┐
│                        INTERFACES                                │
│                                                                  │
│   ┌──────────────┐  ┌──────────────────┐  ┌──────────────────┐  │
│   │   Livewire    │  │  Artisan CLI     │  │   API Routes     │  │
│   │   Pages/SFCs  │  │  Commands        │  │   /api/*         │  │
│   └──────┬───────┘  └───────┬──────────┘  └───────┬──────────┘  │
│          │                  │                      │             │
│          └──────────────────┼──────────────────────┘             │
│                             ▼                                    │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │                     ACTIONS                              │     │
│  │  Single use-case classes. Callable from any interface.   │     │
│  │                                                          │     │
│  │  LoadFileDiffAction, GetFileListAction, ExportReview...  │     │
│  └──────────────────────────┬──────────────────────────────┘     │
│                             │                                    │
│                             ▼                                    │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │                    SERVICES                              │     │
│  │  Stateless domain logic. Injected into Actions.          │     │
│  │                                                          │     │
│  │  GitDiffService, DiffParser, SyntaxHighlightService...   │     │
│  └──────────────────────────┬──────────────────────────────┘     │
│                             │                                    │
│              ┌──────────────┼──────────────────┐                 │
│              ▼              ▼                   ▼                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐       │
│  │    DTOs       │  │   Models     │  │   Support        │       │
│  │  (immutable)  │  │  (Eloquent)  │  │  (DiffCacheKey,  │       │
│  │  FileDiff,    │  │  Project,    │  │   GrammarMap)    │       │
│  │  Hunk, etc.   │  │  Session     │  │                  │       │
│  └──────────────┘  └──────────────┘  └──────────────────┘       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Key rule**: Business logic lives in **Actions**, not in Livewire components or models. Livewire components are thin UI adapters.

---

## Level 3: Directory Tree

```
rfa/
├── rfa                          # Bash entry point (daemon + register)
├── install                      # Installation script
├── CLAUDE.md                    # AI assistant instructions
│
└── src/                         # Laravel application root
    ├── artisan                  # Laravel CLI
    ├── composer.json            # PHP dependencies
    ├── package.json             # JS dependencies (Tailwind)
    │
    ├── app/
    │   ├── Actions/             # 🎯 Business logic (20 action classes)
    │   │   ├── LoadFileDiffAction.php      # Core: load + parse + highlight a file diff
    │   │   ├── GetFileListAction.php       # List changed files in a diff range
    │   │   ├── RegisterProjectAction.php   # Register a repo for review
    │   │   ├── ExportReviewAction.php      # Export comments as JSON/Markdown
    │   │   ├── AddCommentAction.php        # Add inline/file comment
    │   │   ├── DeleteCommentAction.php     # Delete a comment
    │   │   ├── SaveSessionAction.php       # Persist review state to DB
    │   │   ├── RestoreSessionAction.php    # Restore comments/viewed state
    │   │   ├── GetBranchListAction.php     # List git branches
    │   │   ├── GetCommitHistoryAction.php  # List commits on a branch
    │   │   ├── GroupReviewFilesAction.php  # Separate source files vs review exports
    │   │   └── ... (9 more)
    │   │
    │   ├── Services/            # ⚙️  Domain operations (7 service classes)
    │   │   ├── GitDiffService.php          # Get file lists and diffs from git
    │   │   ├── GitProcessService.php       # Low-level git command executor
    │   │   ├── DiffParser.php              # Parse raw diff text → DTOs
    │   │   ├── SyntaxHighlightService.php  # Tokenize + theme via Phiki
    │   │   ├── GitMetadataService.php      # Branch, commit metadata
    │   │   ├── IgnoreService.php           # .rfaignore + .gitignore patterns
    │   │   ├── CommentExporter.php         # Format comments for export
    │   │   └── MarkdownFormatter.php       # Markdown rendering helper
    │   │
    │   ├── DTOs/                # 📦 Immutable data containers (8 DTOs)
    │   │   ├── FileDiff.php               # Parsed diff for one file
    │   │   ├── Hunk.php                   # One @@ chunk within a diff
    │   │   ├── DiffLine.php               # Single line (add/remove/context)
    │   │   ├── DiffTarget.php             # Git range (from..to or working dir)
    │   │   ├── FileListEntry.php          # File metadata in file list
    │   │   ├── Comment.php                # A review comment
    │   │   ├── BranchEntry.php            # Branch info
    │   │   ├── CommitEntry.php            # Commit info
    │   │   └── ReviewFilePair.php         # Paired review export files
    │   │
    │   ├── Models/              # 💾 Eloquent models (2 models)
    │   │   ├── Project.php                # Registered git project
    │   │   └── ReviewSession.php          # Persisted review state
    │   │
    │   ├── Support/             # 🔧 Utilities
    │   │   ├── DiffCacheKey.php           # Cache key generation for diffs
    │   │   └── GrammarMap.php             # File extension → syntax grammar
    │   │
    │   ├── Enums/
    │   │   └── DiffSide.php               # Left/Right side enum
    │   │
    │   ├── Exceptions/
    │   │   └── GitCommandException.php
    │   │
    │   ├── Console/Commands/
    │   │   └── RegisterProjectCommand.php # `artisan rfa:register {path}`
    │   │
    │   └── Providers/
    │       └── AppServiceProvider.php
    │
    ├── resources/
    │   ├── css/app.css                    # Tailwind + custom theme tokens
    │   └── views/
    │       ├── layouts/app.blade.php      # HTML shell (fonts, CSS, JS)
    │       ├── pages/                     # Full-page Livewire SFCs
    │       │   ├── ⚡dashboard-page.blade.php   # Project list
    │       │   └── ⚡review-page.blade.php      # Main review interface
    │       ├── livewire/                  # Reusable Livewire SFCs
    │       │   ├── ⚡diff-file.blade.php         # Individual file diff
    │       │   ├── ⚡branch-explorer.blade.php   # Branch/commit picker
    │       │   ├── ⚡theme-switcher.blade.php    # Light/dark toggle
    │       │   ├── ⚡update-checker.blade.php    # Version checker
    │       │   ├── submit-bar.blade.php          # Export button
    │       │   └── undo-toast.blade.php          # Undo notification
    │       └── components/               # Blade partials
    │           ├── comment-form.blade.php
    │           └── comment-display.blade.php
    │
    ├── routes/web.php                    # Routes (3 Livewire + 3 API)
    ├── config/                           # Laravel config files
    │   └── rfa.php                       # App-specific config
    ├── database/
    │   └── migrations/                   # 6 migration files
    │
    ├── public/
    │   ├── index.php                     # Laravel entry point
    │   ├── fonts/                        # JetBrains Mono, Space Grotesk (local)
    │   └── js/                           # Alpine.js behaviors
    │       ├── diff-file.js
    │       ├── branch-explorer.js
    │       └── tailwind.js
    │
    └── tests/
        ├── Unit/                         # ~40 unit test files
        │   ├── Actions/                  # Tests for every action
        │   ├── DTOs/                     # DTO behavior tests
        │   ├── Livewire/                 # Component tests
        │   └── Support/                  # Utility tests
        ├── Arch/                         # ~12 architecture tests
        │   ├── LayerDependenciesTest.php # Enforces layer boundaries
        │   ├── NoExternalResourcesTest.php  # No CDNs/Google Fonts
        │   └── ...
        ├── Browser/                      # ~15 browser/E2E tests
        └── Fixtures/                     # Sample .diff files
```

---

## Level 4: Data Flow — From CLI to Pixels

### Phase 1: Project Registration

```
Terminal                     Laravel                      SQLite
───────                     ───────                      ──────
./rfa
  │
  └─ php artisan
     rfa:register /path
         │
         ▼
  RegisterProjectCommand
         │
         ▼
  RegisterProjectAction
    ├─ detect branch name
    ├─ detect worktree
    ├─ generate slug
    │
    └──────────────────────────────────────────────► Project::updateOrCreate()
                                                      slug, name, path,
                                                      branch, is_worktree
```

### Phase 2: Page Load (Review Page)

```
Browser: GET /p/my-project
          │
          ▼
    ┌─ ReviewPage::mount() ─────────────────────────────┐
    │                                                     │
    │  1. ResolveProjectAction                            │
    │     └─ Find Project by slug                         │
    │                                                     │
    │  2. Build DiffTarget                                │
    │     ├─ Working dir: DiffTarget::workingDirectory()  │
    │     ├─ Commit:      DiffTarget::fromRefs(hash)      │
    │     └─ Range:        DiffTarget::fromRefs(ref,base)  │
    │                                                     │
    │  3. GetFileListAction                               │
    │     └─ GitDiffService::getFileList()                │
    │        └─ git diff --name-status --numstat          │
    │        → FileListEntry[] (paths, stats, status)     │
    │                                                     │
    │  4. GroupReviewFilesAction                           │
    │     └─ Separate .rfa.json/.rfa.md into reviewPairs  │
    │                                                     │
    │  5. RestoreSessionAction                            │
    │     └─ Load comments + viewedFiles from DB          │
    │                                                     │
    │  6. render()                                        │
    │     ├─ Sidebar: file list with stats                │
    │     └─ Main: <livewire:diff-file> per file (lazy)   │
    └─────────────────────────────────────────────────────┘
```

### Phase 3: Diff Loading (Per File, Lazy)

```
Browser scrolls to file
          │
          ▼
    DiffFile x-intersect.once
          │
          ├─ staggered delay (avoids thundering herd)
          │
          ▼
    DiffFile::loadFileDiff()
          │
          ▼
    LoadFileDiffAction::handle()
          │
          ├─ 1. Check cache (self-healing)
          │     └─ DiffCacheKey::for(projectId, fileId, contextKey)
          │        → "rfa_diff_v6_{xxh128_hash}"
          │     └─ Validate: has 'syntaxStyles' + 'isSymlink' keys?
          │        ├─ YES → return cached data
          │        └─ NO  → continue (stale entry, recompute)
          │
          ├─ 2. Get raw diff
          │     └─ GitDiffService::getFileDiff()
          │        └─ GitProcessService::run(['diff', ...])
          │           └─ git -c core.quotepath=false -C {repo} diff ...
          │
          ├─ 3. Parse
          │     └─ DiffParser::parseSingle()
          │        → FileDiff { hunks: Hunk[], status, additions, deletions }
          │             └─ Hunk { lines: DiffLine[], header, oldStart, newStart }
          │                  └─ DiffLine { type, content, oldLineNum, newLineNum }
          │
          ├─ 4. Syntax highlight
          │     └─ SyntaxHighlightService::highlightHunks()
          │        ├─ GrammarMap::resolve('file.php') → Grammar::Php
          │        ├─ Phiki::codeToTokens(content, grammar)
          │        │  → tokens with TextMate scopes
          │        ├─ Theme matching (scope-cached for O(1) lookups)
          │        │  ├─ Light: GitHub Light
          │        │  └─ Dark:  GitHub Dark
          │        ├─ Build <span class="_abc12">token</span> per token
          │        └─ Build CSS class map: ._abc12 { color: #xxx }
          │           → DiffLine.highlightedContent populated
          │
          └─ 5. Cache result (TTL: 30 days immutable / 24h working dir)
```

### Phase 4: Commenting Flow

```
User clicks line → comment form appears
          │
          ▼
    ReviewPage::addComment()     (Livewire call)
          │
          ├─ AddCommentAction::handle()
          │   └─ Create Comment DTO
          │   └─ Append to $this->comments[]
          │
          ├─ SaveSessionAction::handle()
          │   └─ ReviewSession::updateOrCreate()
          │
          └─ Dispatch 'comment-updated' event to DiffFile
              (targeted event → avoids full re-render)
```

---

## Level 5: Key Subsystems Deep Dive

### The Git Pipeline

```
GitProcessService          GitDiffService            DiffParser
─────────────────          ──────────────            ──────────
  Executes raw               Orchestrates              Parses raw
  git commands               git operations            diff text
  (30s timeout)              for files/diffs           into DTOs

  run(['diff',...])  ◄────  getFileDiff()
       │                         │
       └─ Process::run()         └─ raw diff string
          via Symfony                  │
                                       ▼
                                  parseSingle()
                                       │
                                       ▼
                                  FileDiff {
                                    path, status,
                                    hunks: [Hunk {
                                      lines: [DiffLine {
                                        type: add|remove|context,
                                        content, lineNums
                                      }]
                                    }]
                                  }
```

### The Syntax Highlighting Pipeline

```
SyntaxHighlightService
          │
          ├─ 1. Resolve grammar
          │     GrammarMap::resolve('app.tsx')
          │     → Grammar::TypeScriptReact
          │     (50+ languages, compound extensions, special filenames)
          │
          ├─ 2. Tokenize per hunk
          │     Phiki::codeToTokens(code, grammar)
          │     → [ [token, [scope1, scope2]], ... ]
          │
          ├─ 3. Match themes (cached by scope key)
          │     scope "variable.other.php"
          │       → Light CSS: color: #24292e
          │       → Dark CSS:  color: #e1e4e8
          │       → class: _a7f3b
          │
          └─ 4. Output
                ├─ DiffLine.highlightedContent =
                │    '<span class="_a7f3b">$var</span> = ...'
                └─ syntaxStyles =
                     '<style>._a7f3b{color:#24292e}
                      .dark ._a7f3b{color:#e1e4e8}</style>'
```

### The Caching System

```
                   ┌─────────────────────────────┐
                   │     DiffCacheKey::for()      │
                   │  "rfa_diff_v6_{xxh128(...)}" │
                   └──────────┬──────────────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼                               ▼
    Working Directory                   Commit Range
    ─────────────────                   ────────────
    TTL: 24 hours                       TTL: 30 days
    Cleared when file                   Immutable (safe
    list changes                        to cache long)

              │                               │
              └───────────────┬───────────────┘
                              ▼
                    Self-Healing Validation
                    ──────────────────────
                    Before returning cached data,
                    check for expected keys:
                    ├─ 'syntaxStyles' present?
                    └─ 'isSymlink' present?

                    Missing? → Recompute + overwrite
                    (no version bump needed)
```

### The Frontend Stack

```
┌─────────────────────────────────────────────────┐
│                    Browser                        │
│                                                   │
│  ┌─ Livewire 4.1 ──────────────────────────────┐ │
│  │  Server-rendered components with              │ │
│  │  real-time reactivity via WebSocket-like      │ │
│  │  AJAX polling                                 │ │
│  │                                               │ │
│  │  Pages:                                       │ │
│  │  ├─ DashboardPage (project list)              │ │
│  │  └─ ReviewPage (main review + all state)      │ │
│  │                                               │ │
│  │  Components:                                  │ │
│  │  ├─ DiffFile (one per changed file)           │ │
│  │  ├─ BranchExplorer (branch/commit picker)     │ │
│  │  ├─ ThemeSwitcher (light/dark)                │ │
│  │  ├─ SubmitBar (export review)                 │ │
│  │  └─ UndoToast (undo deleted comments)         │ │
│  └───────────────────────────────────────────────┘ │
│                                                   │
│  ┌─ Alpine.js ──────────────────────────────────┐ │
│  │  Client-side interactivity:                   │ │
│  │  ├─ Sidebar resize (localStorage persist)     │ │
│  │  ├─ File filter/search                        │ │
│  │  ├─ Keyboard navigation                       │ │
│  │  ├─ Intersection observer (lazy loading)      │ │
│  │  └─ Targeted event listeners                  │ │
│  └───────────────────────────────────────────────┘ │
│                                                   │
│  ┌─ Tailwind CSS + Flux 2.12 ──────────────────┐ │
│  │  ├─ gh-* theme tokens (GitHub-like theme)     │ │
│  │  ├─ Light/dark mode via .dark class           │ │
│  │  └─ Local fonts: JetBrains Mono, Space Grotesk│ │
│  └───────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

---

## Level 6: Routing Map

```
URL                              Component            Purpose
─────────────────────────────    ──────────────────   ─────────────────────
/                                DashboardPage        List all projects
/p/{slug}                        ReviewPage           Working dir diff
/p/{slug}/c/{hash}               ReviewPage           Single commit diff
/p/{slug}/{ref}/{baseRef}        ReviewPage           Range diff (ref..base)

/api/status/{project}            (closure)            Git status check
/api/changes/{project}           (closure)            Change fingerprint
/api/image/{project}/{ref}/{p}   ServeImageAction     Serve image from git
```

---

## Level 7: Database Schema

```
┌──────────────────────────────┐     ┌──────────────────────────────────┐
│          projects             │     │        review_sessions            │
├──────────────────────────────┤     ├──────────────────────────────────┤
│ id                           │◄────│ project_id (nullable FK)         │
│ slug          (unique)       │     │ id                               │
│ name                         │     │ repo_path                        │
│ path          (repo root)    │     │ context_fingerprint              │
│ git_common_dir               │     │ comments        (JSON array)     │
│ is_worktree   (boolean)      │     │ global_comment  (text)           │
│ branch                       │     │ viewed_files    (JSON array)     │
│ global_gitignore_path        │     │ created_at / updated_at          │
│ respect_global_gitignore     │     └──────────────────────────────────┘
│ created_at / updated_at      │
└──────────────────────────────┘

Storage: SQLite (src/database/database.sqlite)
```

---

## Level 8: Testing Strategy

```
tests/
├── Unit/           (~40 files)    Pure logic tests
│   ├── Actions/    (1 test per action — full coverage)
│   ├── DTOs/       (DTO behavior)
│   ├── Livewire/   (Component integration)
│   └── Services    (DiffParser, GitDiffService, etc.)
│
├── Arch/           (~12 files)    Architecture enforcement
│   ├── LayerDependenciesTest      Actions don't import Livewire
│   ├── NoExternalResourcesTest    No CDNs in Blade templates
│   ├── ActionsTest                Actions are final, invokable
│   ├── DTOsTest                   DTOs are readonly
│   └── ...
│
├── Browser/        (~15 files)    E2E with real browser
│   ├── PageLoadTest               Pages render
│   ├── InlineCommentTest          Commenting workflow
│   ├── SessionPersistenceTest     State survives reload
│   └── ...
│
└── Fixtures/       (.diff files)  Sample diffs for parser tests

Commands:
  cd src && composer test:lint    # Pint (code style)
  cd src && composer test:types   # PHPStan (static analysis)
  cd src && composer test         # Pest (unit + arch + browser)
```

---

## Summary: How It All Connects

```
  ┌──────┐    ┌────────────┐    ┌───────────┐    ┌──────────┐
  │ User │───▶│  ./rfa     │───▶│  artisan  │───▶│ register │
  │      │    │  (bash)    │    │  serve    │    │ project  │
  └──────┘    └────────────┘    └───────────┘    └──────────┘
                                      │
                                      ▼
                               ┌─────────────┐
                               │   Browser    │
                               │  Livewire    │
                               └──────┬──────┘
                                      │
                    ┌─────────────────┼──────────────────┐
                    ▼                 ▼                   ▼
             ┌────────────┐  ┌──────────────┐  ┌──────────────┐
             │  Actions    │  │  Actions     │  │  Actions     │
             │  (file list)│  │  (load diff) │  │  (comments)  │
             └─────┬──────┘  └──────┬───────┘  └──────┬───────┘
                   │                │                  │
                   ▼                ▼                  ▼
             ┌────────────────────────────┐    ┌────────────┐
             │       Services             │    │   SQLite    │
             │  Git → Parse → Highlight   │    │   (state)  │
             └────────────┬───────────────┘    └────────────┘
                          │
                          ▼
                    ┌───────────┐
                    │  Your Git │
                    │   Repo    │
                    └───────────┘
```

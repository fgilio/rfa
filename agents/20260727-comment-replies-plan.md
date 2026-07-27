# Comment Replies Plan

## Outcome

Add durable, UI-driven reply threads to every RFA comment without changing what a comment means today:

- a comment remains the anchored review/context item
- replies form a chronological conversation under that comment
- the same backend Actions will later power Codex CLI, Claude Code, or another agent interface
- existing comment anchoring, submission, drafts, counts, trash, and 1+N render protections remain intact

## Decisions

### Separate replies from comments

Create a `comment_replies` table instead of making `comments` self-referential.

Comments own file/diff anchors, draft state, and submission state. Replies own none of those. Keeping them separate prevents replies from:

- needing fake file/line anchor data
- appearing as independent comments
- affecting review submission or divergence checks
- inflating project, sidebar, or drawer comment counts
- complicating the existing comment pool queries

### Flat threads

A reply belongs directly to one root comment. Replies render oldest-first as a flat conversation.

Do not add nested reply trees or `parent_reply_id`. Codex and Claude can still converse by appending messages to the same ordered thread, while the UI stays scannable.

### Sender identity is part of the primitive

Persist enough identity for later CLI callers from day one:

- `author_type`: `human` or `agent`, enforced through a PHP enum
- `author_key`: stable machine identifier such as `rfa-ui`, `codex-cli`, or `claude-code`
- `author_label`: optional display label such as `Codex` or `Claude Code`

The UI must not accept these fields from browser event payloads. It always injects the trusted human identity. A future CLI will construct an agent identity and call the same Action.

`CommentAuthor::human()` uses `author_type=human`, `author_key=rfa-ui`, and no stored label. The presentation layer renders that identity as `You`. The distinct key avoids duplicating `human` in two fields and identifies the concrete RFA UI caller.

### Replies are conversation, not review submission

Replies:

- have no `is_draft`
- have no `submitted_at`
- are never included in the generated review/context Markdown in this phase
- do not reopen or unsubmit their root comment
- remain attached when the root comment is submitted

This isolates the conversational primitive from the existing "export this directive to an agent" lifecycle. The CLI phase must read and write complete threads through the reply Actions/read Action. It must not expect Markdown exports to contain replies.

### Preserve existing count semantics

Every existing "comment count" continues to count root comments/threads, not messages. The UI may show a separate `N replies` label on a thread.

### SQLite cascades are a tested runtime contract

RFA's SQLite connection currently sets `foreign_key_constraints` from `DB_FOREIGN_KEYS`, defaulting to `true`. Root deletion may rely on `ON DELETE CASCADE` only if tests prove both:

- the real test/application SQLite connection reports `PRAGMA foreign_keys = 1`
- deleting a root through that connection actually removes its reply rows

This turns foreign-key enforcement into a regression-tested application contract instead of assuming the migration definition is sufficient.

## Data Model

### New `comment_replies` table

| Column | Type | Rules |
| --- | --- | --- |
| `id` | string primary key | `r-` + ULID |
| `comment_id` | string foreign key | references `comments.id`, cascade on delete |
| `author_type` | string | `human` or `agent` |
| `author_key` | string | normalized, non-empty, max 100 |
| `author_label` | nullable string | max 100 |
| `body` | text | non-empty after trimming. No artificial max, matching root comments |
| `created_at` / `updated_at` | timestamps | normal Laravel timestamps |

Add a composite index on `comment_id, created_at`. Always order by `created_at, id` so ties are deterministic.

Use an explicit string foreign key definition because `comments.id` is a string. `foreignId()` would create the wrong integer type.

### Models and DTOs

- Add `App\Models\CommentReply` with string, non-incrementing IDs and a `comment()` relation.
- Add ordered `replies()` relation to `App\Models\Comment`.
- Add `Database\Factories\CommentReplyFactory`.
- Add `App\Enums\CommentAuthorType`.
- Add immutable `App\DTOs\CommentAuthor` with `human()` and `agent()` named constructors.
- Add immutable `App\DTOs\CommentReply` as the normalized camel-case boundary shape.
- Add version-tolerant `App\DTOs\CommentThreadSnapshot` for root delete/clear/trash undo payloads.
- Extend `App\DTOs\Comment` with `replies: list<CommentReply>`.

Normalized reply view shape:

```text
id
commentId
authorType
authorKey
authorLabel
body
createdAt
updatedAt
```

## Backend Architecture

### Single-use-case Actions

Add:

- `LoadCommentThreadAction`
- `AddCommentReplyAction`
- `UpdateCommentReplyAction`
- `DeleteCommentReplyAction`
- `RestoreCommentReplyAction`

Every Action receives the repository scope (`repoPath`, optional `projectId`) and resolves the root through `Comment::forProjectOrRepo(...)`. This prevents a stale or forged ID from mutating another repository.

`LoadCommentThreadAction` is the canonical scoped read primitive for both the workflow and the future CLI. It returns the normalized root plus ordered replies, including submitted roots when requested.

Update/delete additionally receive the acting `CommentAuthor` and only mutate replies owned by that author. The initial UI can therefore edit/delete human replies but cannot rewrite future agent replies.

`AddCommentReplyAction` must allow roots that are already submitted. Submitted threads remain available through the comments drawer.

### UI workflow Action

Add `CommentReplyWorkflowAction` as the UI-oriented coordinator over the single-use-case Actions, parallel to `ReviewCommentWorkflowAction`.

It returns a `CommentReplyMutation` DTO containing:

- root `commentId`
- root `filePath`
- the complete ordered reply list after the mutation
- optional undo payload/message

Reply mutations always skip the parent render, so the DTO does not carry a redundant render flag. The workflow does not perform divergence checks. A reply does not change comment risk, file contents, anchors, or submission scope.

### Loading

Eager-load ordered replies everywhere roots enter a view:

- `SessionStateAction`
- `LoadContextCommentsAction`
- comments drawer query Action

Then normalize them through `App\DTOs\CommentReply`. `ResolveCommentAnchorAction` and `ResolveContextCommentAnchorAction` must preserve the nested `replies` collection while resolving only the root anchor.

Move the comments drawer's growing read query behind `LoadCommentsDrawerAction` while adding reply support. It should:

- eager-load replies in one additional query, never one query per root
- match filters against file path, root body, reply body, and reply author label/key
- keep `totalCount` scoped to roots only
- group the root predicates and `whereHas`/`orWhereHas` reply predicates so repository/submission scope can never leak through an ungrouped `OR`

### Root mutation integrity

Root deletion relies on the database cascade. Undo must restore the whole thread:

- load the root and replies and build the undo snapshot before executing the root delete because the cascade runs immediately
- emit new snapshots as `{version: 1, comment: {...}, replies: [...]}` through `CommentThreadSnapshot`
- accept legacy raw-comment snapshots with no `version` or `replies` key and normalize them to `replies=[]`
- restore the root and its replies in one transaction
- preserve reply IDs, author data, bodies, and timestamps
- make clear-all restore every thread atomically
- make context-page restore follow the same Action boundary instead of directly recreating only the root model

The existing discard-file JSON snapshot must move through the same version-tolerant snapshot DTO. Old `trashed_files.comments` payloads remain restorable. New payloads preserve replies. Add regression coverage for both formats.

### Backend flow

```text
UI reply action
└── ReviewPage or ContextPage Livewire handler
    └── CommentReplyWorkflowAction
        ├── scope root to project/repository
        ├── call add/update/delete/restore Action
        ├── reload ordered replies
        └── return CommentReplyMutation
            ├── update matching root in page state, if currently loaded
            ├── dispatch targeted comment-thread-updated
            ├── dispatch undo-available when deleting
            └── always skip parent render
                ├── matching DiffFile updates one thread
                └── comments drawer refreshes its read model
```

The handler must still persist a reply when the root is submitted and absent from the page's current `$comments`. In that case only the drawer needs refreshing.

## Livewire Event Contract

Add:

| Event | Direction | Payload |
| --- | --- | --- |
| `add-comment-reply` | UI → page | `{commentId, body}` |
| `update-comment-reply` | UI → page | `{replyId, body}` |
| `delete-comment-reply` | UI → page | `{replyId}` |
| `comment-thread-updated` | page → DiffFile/drawer | `{commentId, fileId?, replies}` |

Add `delete-reply` to the central `undo-available` / page `undo()` contract.

Do not put `authorType`, `authorKey`, or `authorLabel` in browser-originated events.

The existing undo toast is a LIFO stack, not a single pending slot. Preserve that behavior: a reply delete followed by a root/comment delete keeps both entries, exposes the newest first, and refreshes the older entry's TTL when it becomes visible. Add a regression test for this mixed sequence.

`DiffFile` gets a narrow `updateCommentReplies(commentId, replies)` method. The window listener checks `fileId` before calling it. This updates one child instead of resending/re-hydrating every diff file.

Both ReviewPage and ContextPage use the same workflow Action and mutation application pattern. Reply writes always skip the parent render.

## UI

### Inline thread

Extend `x-comment-display` with a reusable replies partial/component:

```text
Root comment
├── existing Copy / Edit / Delete controls
├── Reply control
└── replies
    ├── vertical thread rail
    ├── author + timestamp
    ├── body
    ├── Copy
    ├── Edit/Delete when authored by the human UI identity
    └── compact reply composer
```

Behavior:

- The replies component is pure props-in/events-out Blade: no `$this`, page method, `DiffFile`, or surrounding Livewire component assumptions. Review, context, and drawer callers provide the comment/replies and receive the same browser events.
- Reply opens an auto-sizing Flux textarea directly below the thread.
- Focus moves into the textarea.
- `⌘↵` submits through the existing focused `[data-comment-form]` shortcut contract.
- Escape cancels the reply composer. Replies do not create drafts.
- Empty/whitespace-only replies cannot submit.
- After add/update, focus returns to the relevant reply/thread control.
- Copy works for every reply.
- Root comment edit state and reply composer state remain independent.
- Reply bodies use the same plain, whitespace-preserving rendering as root comments.
- A reply whose `updatedAt` differs from `createdAt` shows a subtle `edited` marker beside its timestamp. This intentionally makes conversational edits visible even though legacy root comments do not expose timestamps.
- Each reply row is keyed by reply ID so Livewire morphing preserves local focus/state.

Use Flux textarea, button, tooltip, text, and icon components. Custom markup is limited to the compact thread rail/layout that Flux does not provide.

### Comments drawer

The drawer must expose complete threads, including submitted roots:

- show `N replies` on each root row
- add a thread expand/collapse control that does not trigger navigation
- render the same reply list and composer inside an expanded row
- preserve the existing click-to-scroll behavior for active inline roots
- expand rather than attempt to scroll when a submitted root has no inline target
- keep the drawer open after adding/editing/deleting a reply
- let filtering match reply text and author

This is the UI surface where future CLI-authored replies remain visible after their root was submitted.

### Visual and count behavior

- Replies are visually subordinate to the root through indentation, rail, smaller metadata, and the existing surface colors.
- Agent replies show their `authorLabel`, falling back to `authorKey`.
- Human replies show `You`.
- Existing comment badges, project counts, context summaries, draft counts, and review-risk checks remain root-only.

## Lifecycle Rules

```text
Submit root comment
├── root gets submitted_at
├── root leaves active inline view
├── replies remain untouched
└── full thread remains available in drawer when submitted comments are shown

Delete root comment
├── workflow snapshots root + replies before deletion
├── database cascades reply deletion
└── undo restores the versioned snapshot atomically

Clear all comments
├── workflow snapshots every thread before deletion
├── database cascades each thread
└── undo restores all versioned snapshots atomically

Delete one reply
├── root remains
├── counts remain unchanged
└── undo restores reply at its original chronological position

Discard/restore file
├── new snapshots carry nested replies with the root
└── legacy snapshots without replies restore as empty threads
```

## Tests

### Migration and model

- migration creates the string FK, composite index, and cascade
- rollback removes only `comment_replies`
- real SQLite connection config has foreign-key constraints enabled
- `PRAGMA foreign_keys` reports enabled in the test connection
- deleting a root through that connection cascades its replies
- model relations return replies in deterministic chronological order
- factory creates valid human and agent replies

### Actions and DTOs

- add persists normalized body, author metadata, and `r-` ULID
- add accepts a submitted root
- add accepts the same unbounded `text` body size as root comments
- add rejects blank body, unknown root, and cross-repository/project root
- update/delete reject another author and out-of-scope IDs
- update preserves identity and creation timestamp
- update changes `updatedAt`, enabling the UI's `edited` marker
- delete returns an undo snapshot
- restore preserves ID, identity, body, and timestamps
- root delete/clear captures every reply before the cascade executes
- snapshot DTO emits version 1 and accepts legacy raw comments without replies
- DTOs accept database snake case and emit the established camel-case view shape
- workflow returns the full ordered thread, carries no redundant render flag, and never requests divergence work

### Loading and lifecycle

- review and context loaders include ordered replies
- anchor resolution preserves replies for placed, shifted, and unplaced roots
- loading many roots uses eager loading rather than N+1 queries
- drawer filtering matches reply body and author
- drawer reply filtering remains repository/submission scoped and has bounded query-count/runtime coverage
- drawer root count does not increase when replies are added
- root delete/clear undo restores all replies
- legacy delete/trash snapshots without `replies` restore successfully
- discard/restore preserves replies
- review/context Markdown export and submitted IDs remain unchanged by replies

### Livewire and JavaScript

- ReviewPage and ContextPage inject the human identity instead of trusting event identity
- a mutation updates only the matching root/file and skips parent rendering
- a reply to a submitted drawer root persists even when absent from page state
- drawer refreshes on `comment-thread-updated`
- reply composer opens, focuses, cancels, submits, and retains independent edit state
- `⌘↵` routes to the focused reply form
- the same props-only replies component renders in review, context, and drawer hosts without `$this`/`DiffFile` dependencies
- consecutive reply/root undo events remain stacked LIFO rather than replacing each other

### Browser

- add a reply under an inline review comment
- add a reply under a context comment
- edit, copy, and delete a human reply
- edited replies show an `edited` marker
- undo reply deletion
- root delete + undo restores its visible replies
- expand and reply to a submitted thread in the drawer
- filter drawer by reply text/agent name
- reply actions do not close the drawer or navigate
- comment badges/counts remain unchanged after adding replies

### Performance/regression

- extend `diff-with-comments` benchmark fixtures with replies
- retain the one-parent/one-child targeted update contract with many files
- verify reply hydration adds one eager-load query, not one query per comment
- benchmark/filter a drawer dataset with roots and replies, covering reply-body and reply-author `whereHas` paths

## Implementation Order

Recommended delivery split:

```text
PR 1: backend foundation
├── migration, enum, models, DTOs, factories
├── scoped read and single-use-case reply Actions
├── CommentReplyWorkflowAction and mutation DTO
├── versioned/legacy-compatible snapshots
└── model, Action, cascade, snapshot, lifecycle, and export-regression tests

PR 2: UI integration
├── review/context loading and Livewire handlers
├── targeted DiffFile event bridge
├── pure reusable reply thread/composer
├── drawer expansion, submitted threads, and filtering
├── JS/browser/performance coverage
└── architecture documentation/event tables
```

Within those PRs:

1. Land the backend schema and contracts before exposing UI events.
2. Thread replies through loaders, anchor resolution, snapshots, and drawer reads.
3. Add Livewire handlers/events while preserving targeted child updates and `skipRender()`.
4. Build the reusable Flux reply thread/composer and inline UI.
5. Add drawer expansion, submitted-thread support, and reply filtering.
6. Extend performance fixtures and architecture documentation/event tables.
7. Run focused Pest/JS/browser tests, then Pint, PHPStan, the full core suite, and the full browser/performance verification.

## Acceptance Criteria

- Any active review or context comment can receive chronological replies in the UI.
- Submitted comments can receive and display replies through the drawer.
- Replies persist sender identity suitable for a future agent caller.
- The future CLI can call backend Actions without depending on Livewire, Alpine, or Blade.
- Root submission/export behavior is byte-for-byte unchanged.
- Existing comment counts remain root-thread counts.
- Delete, clear-all, discard, restore, and undo never lose replies.
- Legacy undo/trash payloads created before reply support remain restorable.
- SQLite cascade behavior is verified against the configured runtime connection.
- Reply mutations never trigger an N-diff-file parent re-render.
- Drawer filtering over reply fields remains correctly scoped and regression-tested.
- All new behavior is covered at model, Action, Livewire/JS, browser, and lifecycle boundaries.

## Explicitly Out Of Scope

- Artisan/CLI, MCP, HTTP, or deep-link interfaces
- polling or push detection for replies written by another process
- unread/read receipts
- mentions, reactions, resolution status, or notifications
- nested reply trees
- reply drafts
- exporting replies into review/context Markdown
- multi-user authentication or permissions

## Unresolved Questions

None. The CLI phase can add an interface over these Actions without a schema or UI rewrite.

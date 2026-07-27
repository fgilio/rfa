# Feedback: Comment Replies Plan

Review of `20260727-comment-replies-plan.md` against the current codebase.

## Verdict

Solid plan, ready to implement. Verified against the code: `Comment::scopeForProjectOrRepo` exists (`app/Models/Comment.php:47`), the `'c-'.Str::ulid()` ID convention matches (`app/Actions/AddCommentAction.php:71`, so `'r-'.Str::ulid()` is consistent), `ReviewCommentWorkflowAction` / `ContextCommentWorkflowAction` are real precedents for `CommentReplyWorkflowAction`, and the comments drawer query is indeed inline in `resources/views/livewire/⚡comments-drawer.blade.php` today — moving it behind an Action while adding replies is the right call. The separate-table decision, flat threads, and trusted-identity injection are all correct.

## Concerns worth addressing before/while implementing

1. **SQLite cascade dependency.** Root-delete relies on `ON DELETE CASCADE`, which in SQLite only fires when `foreign_keys` is on. Laravel enables it by default for SQLite connections, but the plan's cascade tests should assert the cascade actually fires against the real connection config, not just the migration definition — otherwise a config regression silently orphans replies.

2. **Undo restore vs. cascade timing.** Root undo restores root + replies with preserved IDs/timestamps in one transaction — good. Make sure the trash/undo snapshot is taken *before* the delete executes (the cascade destroys replies immediately), and that the snapshot format is versioned/tolerant, since existing root undo payloads in `ManagesReviewTrash` won't have a `replies` key. Restoring an old-format snapshot must not throw.

3. **Edited-reply indication.** Update preserves identity and `created_at`, but the plan never says whether the UI shows an "edited" state (root comments' behavior should be mirrored, whatever it is). Minor, but decide it now so `updatedAt` in the DTO isn't dead weight.

4. **`author_key = 'human'` overlaps `author_type = 'human'`.** Harmless but slightly confusing in queries and fixtures. Consider `author_key = 'you'`/`'ui'` or just accept the duplication and document it in the enum/DTO docblock.

5. **Drawer filter over reply text.** Matching filters against reply body/author means `whereHas`/`orWhereHas` subqueries on `comment_replies`. Fine at RFA scale, but add the filter path to the performance/regression tests (the plan's benchmark bullet covers hydration, not filtering).

6. **Undo toast concurrency.** Adding `delete-reply` to the `undo-available` contract: the current toast holds one pending undo. Deleting a reply then deleting its root (or another comment) drops the first undo. That matches existing semantics for consecutive comment deletes, so it's acceptable — but state it explicitly in the plan/tests so it's a decision, not an accident.

7. **Replies excluded from Markdown export.** Deliberate and correctly scoped, but it means agent-facing exports show a root comment with no trace that a conversation resolved/amended it. Fine for this phase; flag in the drawer UI is enough. Just confirm the CLI phase reads threads via the Actions (the plan says so) rather than expecting export to carry them, since that expectation is easy to develop.

8. **`x-comment-display` reuse across surfaces.** The replies partial must render identically inline (review + context pages) and inside the drawer's expanded row, where there's no `DiffFile` parent. Keep the partial free of any `$this`/page-component assumptions — pure props in, events out — or the drawer embedding gets awkward.

## Small nits

- Table spec: add a max length to `body`? Root comments presumably have none; mirror whatever they do — just be consistent.
- `CommentReplyMutation`'s "whether the parent can skip rendering" — in practice this is always true per the plan's own rule ("Reply writes always skip the parent render"); consider dropping the flag and hard-coding the behavior.
- Implementation order is right; consider landing steps 1–3 as one PR (pure backend, no UI risk) and 4–7 as a second.

## Unresolved questions

None blocking. Items 3, 4, and 6 above are decisions to make, not open research.

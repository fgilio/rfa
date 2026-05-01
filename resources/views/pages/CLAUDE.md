# Pages

Pages are the entry-point surfaces of the app. The user reaches them via UI affordances — header buttons, native menu items (`HandleMenuItemClicked`), deep-links (`HandleDeepLink`), the `./rfa` terminal helper — never by typing a URL. Adding a route in `routes/web.php` does not expose a page; the page becomes reachable only when something in the UI navigates to it.

When designing a new page or mode, the pivotal question is **where does the user click to enter this?** — not what the route looks like.

## Known Debt

- **review-page comment writes.** The page (`⚡review-page.blade.php`) owns five responsibility clusters: initialization, comment writes (`addComment` / `addDraftComment` / `updateComment` / `deleteComment`), trash/restore/clear-all, reviewed-file state, and session persistence. Second-opinion review landed on extracting a `ReviewCommentWorkflow` service at the app layer that the four comment methods delegate to (inputs: `repoPath`, `projectId`, `DiffTarget`, current `$files` and `$comments`; output: a result DTO with new comments, affected file ids, undo payload, undo message). **Do not** move writes into `comments-drawer`: the drawer is intentionally a lazy read-model and the writes couple to diff/session state it doesn't own. Trash/restore/clear-all stay in the page (session-state concerns, not comment concerns).

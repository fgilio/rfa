# Pages

Pages are the entry-point surfaces of the app. The user reaches them via UI affordances — header buttons, native menu items (`HandleMenuItemClicked`), deep-links (`HandleDeepLink`), the `./rfa` terminal helper — never by typing a URL. Adding a route in `routes/web.php` does not expose a page; the page becomes reachable only when something in the UI navigates to it.

When designing a new page or mode, the pivotal question is **where does the user click to enter this?** — not what the route looks like.

## Known Debt

- **review-page comment writes (resolved).** The comment writes (`addComment` / `addDraftComment` / `updateComment` / `deleteComment`) delegate to `ReviewCommentWorkflowAction`, which returns a `ReviewCommentMutation` DTO (new comments, affected file ids, undo payload/message, and the render-settle flags). It must be an **Action**, not a service: the arch tests forbid Livewire from using Services directly and forbid Services from touching Eloquent models, and the write path touches the `Comment` model. It is a sibling to `ContextCommentWorkflowAction`. The page keeps the dispatch and `skipRender` in `applyCommentMutation()` so the 1+N hydration contract holds (an Action cannot reach the render pipeline). **Do not** move writes into `comments-drawer`: the drawer is intentionally a lazy read-model and the writes couple to diff/session state it doesn't own. Trash/restore/clear-all stay in the page (session-state concerns, not comment concerns).

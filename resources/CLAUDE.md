# Frontend Conventions

## Components
- Always use Flux UI components over raw Tailwind/Alpine when available
- Check fluxui.dev docs for component API before building custom markup

## Typography
- **Display font** (`font-display` / Space Grotesk): headings, labels, navigation text, buttons
- **Mono font** (`font-mono` / JetBrains Mono): code, diffs, file paths, hashes, badges, technical data
- Use `rfa-logo` class for the "rfa" wordmark (bold, tight tracking)
- Use `section-label` class for uppercase section headers (e.g. "Files", "Reviews", "Local", "Remote"). Defaults to display font; add `font-mono` when the label content is technical data like a path or hash (see the picker's group header over `$commonDir`)
- Use `tracking-brutal` (-0.04em) on headings and `tracking-brutal-tight` (-0.06em) for display sizes
- Diff code areas stay dense: `font-mono text-xs leading-5`

## Paths & identifiers

A file path is two pieces of information stitched together: the **identifier** (basename — what the file is) and the **context** (directory — where it lives, used to disambiguate). When users scan a list, they're hunting for the identifier; the directory only matters when basenames collide. Render them with that hierarchy.

### Rules

- **Identifier-first hierarchy via color, not weight.** Directory renders in `text-gh-muted/70`; basename renders in `text-gh-text` (full color, default weight). No `font-semibold` or `font-medium` — weight stays consistent across the line so the rhythm of a long list doesn't read as ink-heavy.
- **Use real muted color, not opacity.** The directory is `text-gh-muted/70`, not `opacity-60`. Opacity multiplies against whatever the parent text color already is, which compounds to illegibility inside already-muted parents. Real colors stay legible regardless of context.
- **Use `<x-file-path>`.** All path rendering goes through `resources/views/components/file-path.blade.php`. New ad-hoc `{{ $file['path'] }}` inside a `font-mono` span is a code smell — replace it.
- **Preserve the path semantically.** Path order stays `directory/basename`. The DOM is one continuous text flow so selection and copy yield the real path. The full path lives in `title` (the component sets it by default; callers can override).
- **Truncation is whole-line, right-side ellipsis.** The component is a single `block truncate` line; when the row narrows, the basename ellipsizes from the right (e.g. `some/long/dir/very-long-…`). The previous "basename stays whole" rule was a layout footgun in nested flex contexts and was dropped. The full path lives in `title` for hover recovery. Don't wrap the component in your own `truncate` — it already truncates.
- **Renames use `:old-path`.** Don't hand-roll the `→` arrow. The component renders `oldPath → newPath` with the old side at `text-gh-muted/50` (more faded than the regular directory) and the new side following identifier-first emphasis.
- **Annotations stay muted.** Symlink targets, last-modified hints, and similar metadata render at the same muted level regardless of position. They're not part of the identifier.
- **Reordered (basename-first) form is reserved for fuzzy pickers.** If/when a command-palette-style search lands, that's where `HasTaxonomies.php  ·  app/Domains/Metadata/Traits` belongs. Don't use it in lists where the path's left-to-right order encodes meaning (changed-files, comments grouping).
- **Basename-only displays don't go through the component.** When you've intentionally extracted just the basename (e.g. trash list, where the row is too narrow for context), just render the string. The component is for path rendering, not single-token labels.
- **Provide `aria-label` / `title` with the full path on interactive containers.** Screen readers need the literal path; color contrast alone isn't reachable.

### Alpine-rendered paths

When a path's text content is set client-side (e.g. `x-text` from a JS object), use the `pathDir(path)` / `pathBase(path)` helpers on the review-page Alpine root rather than re-running the split inline. The shape mirrors `<x-file-path>` so the visual hierarchy stays identical.

## Colors
- Use `gh-*` color tokens from CSS variables
- RGB-based tokens (bg, surface, border, text, muted, accent, green, red) support Tailwind opacity modifiers: `bg-gh-bg/50`
- Raw tokens (add-bg, del-bg, hunk-bg, etc.) are used directly: `bg-gh-add-bg`
- Theme colors defined in `config/theme.php`
- `accent` is high-contrast (near-black in light, near-white in dark) - for structural emphasis (borders, indicators)
- `link` is muted blue for interactive text (links, expandable buttons, active states). Distinct from body text without breaking monochrome vibe
- Prefer raw text `text-gh-green`/`text-gh-red` over `flux:badge` for +/- counts in headers

## Dark Mode
- Managed by Flux's `@fluxAppearance` + `$flux.dark`
- Toggle: `$flux.dark = ! $flux.dark`
- System preference detection is automatic via Flux

## Visual Style
- Brutalist/raw aesthetic: bold type, dramatic scale contrast, generous whitespace in chrome
- Headers use `backdrop-blur-sm bg-gh-bg/80` for frosted glass effect
- Adaptive density: chrome elements breathe, code areas stay compact
- Prefer plain text + font styling over Flux badges for inline stats

## UX Principles

These are the rules of thumb we audit against. They're drawn from Material Design Motion, Apple HIG, Nielsen Norman Group research, WCAG 2.3.x, and the Doherty Threshold (sub-400ms responsiveness). When auditing or adding new UI, walk through this list.

### Motion
- **Durations**: 150–250ms for ordinary state changes; never exceed 400ms (perceived as lag).
- **Easing**: `ease-out` for elements leaving (fast start, soft landing — reads as "departing"); `ease-in-out` or Material's standard easing `cubic-bezier(0.4, 0.0, 0.2, 1)` for state changes that stay on-screen.
- **Direction**: prefer **fade + vertical height-collapse** over horizontal slides for items that belong to a list. Horizontal slides break Gestalt continuity and pull the eye away from the surrounding rows. Use `x-collapse` for height (already loaded) and a CSS opacity transition for the fade — they compose.
- **Asymmetric timing**: leaving slightly faster than entering. Re-appearing items (undo) get the slower entry to draw the eye back to where the change happened.
- **In-flight cancellation**: never block interaction during a transition. Alpine's `x-show` + `x-collapse` snap to the final state when toggled mid-flight — don't reimplement.

### Reversibility
- Every action that hides, deletes, or moves user-visible content must offer undo. The bar is high: silent destructive UI is the bug, not the recovery affordance.
- Reuse the central undo mechanism: dispatch `undo-available` from PHP (`resources/views/livewire/undo-toast.blade.php`) and add a case to `ReviewPage::undo()`. ⌘Z is wired centrally — don't reimplement per-component.
- Payload shape: `{ type: string, payload: mixed, message: string, ttl?: int }`. Keep `payload` self-contained so the undo handler doesn't need ambient state.

### Undo stack semantics
- Each undoable action gets its own stack entry. ⌘Z pops the topmost entry only (LIFO queue), so a single press undoes exactly one action.
- The toast always shows the most recent entry; older entries surface as you undo down the stack.
- Don't coalesce bursts of same-type entries into one. The previous "merge mark-reviewed within 3s" rule made one ⌘Z revert many files, which violates the queue contract.

### Accessibility
- Honor `prefers-reduced-motion`: the global rule in `resources/css/app.css` collapses transition/animation durations to ~0ms. Don't fight it with inline styles or `!important` overrides.
- Use `aria-live="polite"` for transient feedback (toasts already do this).
- Provide keyboard parity for any mouse-only action. ⌘Z for undo, `/` for filter focus, `Esc` to clear.
- Focus must not jump unexpectedly when items are added/removed.

### Spatial continuity
- Items that belong to a list reflow vertically when added/removed; don't slide them in/out horizontally on a vertical list.
- Provide an in-place recovery surface for hidden items when feasible (e.g., the "Recently reviewed" sidebar group on `⚡review-page` shows the last 5 marked-reviewed files so the user can un-mark without leaving Hide-reviewed mode).

### Feedback latency (Doherty Threshold)
- Perceived latency under 100ms = "instant"; under 400ms = "responsive"; above = "lag".
- Use `skipRender()` aggressively on Livewire actions whose UI update is handled client-side via Alpine. The `skipRender` table in this file is the canonical reference — extend it when adding new actions.
- Never wait for the server before showing visual feedback for actions whose outcome is locally predictable.

### Anti-patterns to flag in audits
- Instant DOM-style hide (`display: none`) on user-initiated actions without motion or acknowledgment.
- Horizontal slide-in/out on rows of a vertical list.
- Animations longer than 400ms or shorter than 100ms.
- Missing `prefers-reduced-motion` fallback (covered globally now — flag any per-component overrides that bypass it).
- Destructive or hide-from-view actions without an undo path.
- Coalescing distinct user actions into a single undo entry — ⌘Z must undo exactly one action per press.
- Server round-trip required to render the result of a purely visual toggle.
- Loss of focus or scroll position when items reflow.

### References
- [Material Design — Motion](https://m3.material.io/styles/motion/overview): durations, easing, choreography.
- [Apple HIG — Motion](https://developer.apple.com/design/human-interface-guidelines/motion): subtlety, reduce-motion semantics.
- Nielsen Norman Group — animation duration research (sub-400ms perceived as responsive).
- WCAG 2.3.3 (Animation from Interactions) and Success Criterion on `prefers-reduced-motion`.
- Doherty Threshold (1982): sub-400ms feedback for productivity.

## Livewire SFC Components

Page components live in `resources/views/pages/` (namespace `pages::`). Non-page components live in `resources/views/livewire/` (default namespace).

### No literal `<style>` tags in SFC views

Livewire's SFC parser extracts `<style>` tags from raw file content before Blade compilation (`SingleFileParser::extractStylePortion`). This means a dynamic `<style>` like `<style>{!! $css !!}</style>` gets stripped - the Blade directive is never evaluated.

To emit dynamic CSS, build the tag via Blade output instead:
```blade
{!! '<style>' . $css . '</style>' !!}
```

This avoids the SFC regex while producing identical HTML at runtime.

### Redirecting from component actions

Use `$this->redirect($url, navigate: true)` — not raw `$this->redirect($url)`, `$this->js('window.location...')`, or `Window::url(...)` — from Livewire component actions.

- `navigate: true` routes through Livewire's own SPA pipeline, sequenced after the post-response DOM swap. Matches how the app navigates elsewhere (`Livewire.navigate` in `public/js/branch-explorer.js` and `⚡review-page.blade.php`).
- Raw `$this->redirect()` or synchronous `window.location` writes from a **nested child** get clobbered by the parent's post-response DOM processing — the server action completes but the browser never navigates. Hit on `add-project-menu` (child of `project-picker`).
- `Window::get('main')->url(...)` is for main-process listeners (`HandleDeepLink`, `HandleMenuItemClicked`), not in-renderer Livewire actions.

### Polling: prefer `wire:smart-poll` over `wire:poll`

`wire:poll` keeps firing at the same cadence whether or not the user is on the window. With multiple stacked pollers (head-divergence, update-banner, change-detection) that adds up to a constant request stream even on a backgrounded window. Use `wire:smart-poll` (custom directive registered in `public/js/smart-poll.js`) for any new component that doesn't need sub-second responsiveness.

```blade
<div wire:smart-poll="poll" data-focus="10s" data-blur="5m"></div>
```

Behavior:
- `data-focus` runs while `document.hasFocus() && !document.hidden`.
- `data-blur` runs while the window is unfocused but visible.
- Polling pauses entirely when `document.hidden`.
- Refocusing the window fires one immediate tick before resuming the focused cadence — foregrounding feels instant.
- Intervals are re-read on every tick, so a Blade re-render with new `data-*` values picks up the new cadence on the next tick (see `update-banner.blade.php`'s `pollCadence()` for the status-driven pattern).
- Durations accept the same suffixes as `wire:poll`: `ms`, `s`, `m`, `h`. Missing values pause that mode (e.g. omit `data-blur` for "only poll while focused").

When NOT to use it:
- Sub-second cadence requirements (use `wire:poll` directly — but reconsider whether you actually need that).
- `keep-alive`-style heartbeats — keep using `wire:poll.{N}s.keep-alive`.
- Pure Alpine timers that don't call into Livewire — call `window.smartPoll.startSmartPoll({ window, document, getInterval, onTick })` from `init()` and stop it from `destroy()`. Same focus/visibility/inflight semantics as the directive; see `change-polling` on `⚡review-page.blade.php` for the canonical shape.

The arch test `wire:smart-poll always pairs data-focus and data-blur` enforces the contract.

### Parent-Child: Avoid 1+N Re-renders

ReviewPage (`resources/views/pages/⚡review-page.blade.php`) renders N DiffFile children (`resources/views/livewire/⚡diff-file.blade.php`) - one per changed file. Livewire re-hydrates ALL children when a parent re-renders. With `#[Reactive]` props, Livewire's JS interceptor bundles every reactive child into every parent request - even if the prop didn't change. This hits `TooManyComponentsException` (default limit ~20) on repos with many files.

### Rules

1. **Avoid `#[Reactive]` on child props when parent has many children.** Data pushed via reactive props causes 1+N hydration on every parent action. Use event dispatch for targeted updates instead.

2. **Always `skipRender()` on parent actions** that don't need to re-execute the Blade template. If the UI update is handled client-side (Alpine) or via targeted child calls, skip the parent render.

3. **Use event dispatch for targeted child updates** instead of reactive prop binding:
   - Parent dispatches a browser event with scoped data: `$this->dispatch('comment-updated', fileId: $fileId, comments: $fileComments)`
   - Child listens via Alpine and calls its own Livewire method only when its ID matches: `@comment-updated.window="if ($event.detail.fileId === fileId) $wire.updateComments($event.detail.comments)"`
   - This is a 1-to-1 update instead of 1-to-N re-render.

4. **Stagger lazy loading** when many children load data on intersect. Use `setTimeout` with a delay based on index to prevent thundering herd: `x-intersect.once="setTimeout(() => $wire.loadFileDiff(), {{ $loadDelay }})"`

### Which actions skipRender

| Method | skipRender | Why |
|---|---|---|
| `addComment` | Yes | Dispatches comment-updated event to target child |
| `deleteComment` | Yes | Dispatches comment-updated event to target child |
| `clearAllComments` | Yes | Dispatches comment-updated + undo-available events |
| `restoreComments` | Yes | Dispatches comment-updated events to affected files |
| `updatedGlobalComment` | Yes | No UI change needed server-side |
| `toggleReviewed` | Yes | Always skips the parent render; refreshes the `reviewed-summary` + `file-list` islands, plus the visibility islands (`source-diff-list`, `file-count`, `file-list-header`) in Hide-reviewed mode where the toggle drops the file from the visible set. |
| `hideReviewedFiles` / `showAllFiles` | Yes | Skip the parent render; refresh `reviewed-summary` + `file-list` + the visibility islands as files drop in/out of the visible set. |
| `clearRecentlyReviewed` | Yes | Skip the parent render; refresh `file-list` only in Hide-reviewed mode (where the Recently-reviewed group shows). |
| `submitReview` | No | Replaces entire submit bar UI (submitted state) |
| `discardFileChanges` | No | Structural change: file removed from list, trash updated |
| `restoreDiscardedFile` | No | Structural change: file reappears in list |
| `permanentlyDeleteTrashed` | No | Trash section updated |

### Event Schema

| Event | Dispatched by | Received by | Payload |
|---|---|---|---|
| `add-comment` | DiffFile Alpine -> parent | ReviewPage `#[On]` | `{fileId, side, startLine, endLine, body}` |
| `delete-comment` | DiffFile Alpine -> parent | ReviewPage `#[On]` | `{commentId}` |
| `toggle-reviewed` | Livewire event — unit tests only; runtime goes via the `rfa-toggle-reviewed` bridge below | ReviewPage `#[On]` -> `toggleReviewed` | `{filePath}` |
| `rfa-toggle-reviewed` | DiffFile Alpine + sidebar / Recently-reviewed buttons `$dispatch` (bubbles to window) | ReviewPage root Alpine `@window` -> `$wire.toggleReviewed` | `{filePath}` |
| `rfa-hide-reviewed` | `reviewed-summary` island button `$dispatch` (window) | ReviewPage root Alpine `@window` -> `$wire.hideReviewedFiles` | none |
| `rfa-show-all-files` | `reviewed-summary` island button `$dispatch` (window) | ReviewPage root Alpine `@window` -> `$wire.showAllFiles` | none |
| `rfa-clear-recently-reviewed` | Recently-reviewed "Clear" button `$dispatch` (window) | ReviewPage root Alpine `@window` -> `$wire.clearRecentlyReviewed` | none |
| `comment-updated` | ReviewPage PHP dispatch | DiffFile Alpine `@window` | `{fileId, comments}` |
| `copy-to-clipboard` | DiffFile Alpine/PHP, ReviewPage PHP, comment-display, comments-drawer, branch-explorer | layout `<body>` Alpine `@window` | `{text, toast?}` (if `toast` string is set, a success toast with that text shows on success) |
| `file-reviewed-changed` | DiffFile Alpine `$dispatch`, sidebar reviewed button | DiffFile Alpine `@window` (targeted by `id`) | `{id, reviewed}` |
| `collapse-all-files` | ReviewPage Alpine `$dispatch` | DiffFile Alpine `@window` | none |
| `expand-all-files` | ReviewPage Alpine `$dispatch` | DiffFile Alpine `@window` | none |
| `expand-file` | ReviewPage Alpine `$dispatch` | DiffFile Alpine `@window` | `{id}` |
| `undo-available` | ReviewPage PHP dispatch | undo-toast Alpine `@window` | `{type: 'delete'\|'clear-all'\|'discard'\|'mark-reviewed', payload: comment[]\|int\|{filePaths: string[]}, message: string}` |
| `reviewed-files-reverted` | ReviewPage PHP dispatch (`unmarkReviewed`) | DiffFile Alpine `@window` | `{fileIds: string[]}` |
| `discard-file` | DiffFile Alpine `$dispatch` | ReviewPage `#[On]` | `{fileId}` |
| `fingerprint-reset` | ReviewPage PHP dispatch | change-polling Alpine `@window` | none |
| `open-remote-menu` | DiffFile Alpine `$dispatch` | ReviewPage Alpine `@window` | `{target: 'file'\|'line', fileId, filePath, oldPath, status, side?, start?, end?, clientX, clientY}` |
| `scroll-to-comment` | comments-drawer Alpine `$dispatch` | ReviewPage Alpine `@window` | `{commentId, filePath}` |
| `unfold-for-comment` | ReviewPage Alpine `$dispatch` | DiffFile Alpine `@window` | `{fileId}` |

The `rfa-*` events are a deliberate bridge: a `wire:click` or `$wire.dispatch()`
fired from inside a Livewire island scopes the action to that island, so a
reviewed control nested in an island could only refresh its own island. The
controls instead `$dispatch` a bubbling window event that the page-root Alpine
catches and forwards to `$wire`, letting the action run outside island scope and
settle every affected island (see the skipRender table).

### Known Debt

_(Empty — the previous "diff-file `reset-reviewed-files` listener" item was resolved alongside the mark-reviewed undo work; the listener now exists on diff-file's root.)_

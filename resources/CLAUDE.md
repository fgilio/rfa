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
| `toggleReviewed` | Yes | Sidebar state managed client-side via Alpine |
| `submitReview` | No | Replaces entire submit bar UI (submitted state) |
| `discardFileChanges` | No | Structural change: file removed from list, trash updated |
| `restoreDiscardedFile` | No | Structural change: file reappears in list |
| `permanentlyDeleteTrashed` | No | Trash section updated |

### Event Schema

| Event | Dispatched by | Received by | Payload |
|---|---|---|---|
| `add-comment` | DiffFile Alpine -> parent | ReviewPage `#[On]` | `{fileId, side, startLine, endLine, body}` |
| `delete-comment` | DiffFile Alpine -> parent | ReviewPage `#[On]` | `{commentId}` |
| `toggle-reviewed` | DiffFile Alpine -> parent | ReviewPage `#[On]` | `{filePath}` |
| `comment-updated` | ReviewPage PHP dispatch | DiffFile Alpine `@window` | `{fileId, comments}` |
| `copy-to-clipboard` | DiffFile Alpine/PHP, ReviewPage PHP, comment-display, comments-drawer | ReviewPage Alpine `@window` | `{text, toast?}` (if `toast` string is set, a success toast with that text shows on success) |
| `file-reviewed-changed` | DiffFile Alpine `$dispatch` | ReviewPage Alpine `@window` | `{id, reviewed}` |
| `collapse-all-files` | ReviewPage Alpine `$dispatch` | DiffFile Alpine `@window` | none |
| `expand-all-files` | ReviewPage Alpine `$dispatch` | DiffFile Alpine `@window` | none |
| `expand-file` | ReviewPage Alpine `$dispatch` | DiffFile Alpine `@window` | `{id}` |
| `undo-available` | ReviewPage PHP dispatch | undo-toast Alpine `@window` | `{type: 'delete'\|'clear-all'\|'discard', payload: comment[]\|int, message: string}` |
| `discard-file` | DiffFile Alpine `$dispatch` | ReviewPage `#[On]` | `{fileId}` |
| `fingerprint-reset` | ReviewPage PHP dispatch | change-polling Alpine `@window` | none |
| `show-remote-menu` | DiffFile Alpine `$dispatch` | ReviewPage Alpine `@window` | `{target: 'file'\|'line', fileId, filePath, oldPath, side?, start?, end?, clientX, clientY}` |

### Known Debt

- **diff-file's Alpine `reviewed` state isn't reset by `reset-reviewed-files`.** The DiffFile's local `reviewed` mirror (initialized from the `isReviewed` Livewire prop, driving the checkbox and auto-collapse) has no `@reset-reviewed-files.window` listener. If any flow fires `reset-reviewed-files`, the review-page sidebar clears its `reviewedFiles` map and the comments-drawer refreshes, but individual diff-file checkboxes can visually remain checked until the component re-hydrates. Today this is masked because reset paths are paired with full reloads / navigation. Fix is a one-line handler: `@reset-reviewed-files.window="reviewed = false; collapsed = false"` on the diff-file root. Deferred to keep the mirror decision reversible; migrating the whole file to `@entangle('isReviewed').live` would be the alternative but requires moving `toggleReviewed` from review-page into diff-file.

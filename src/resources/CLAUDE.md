# Frontend Conventions

## Components
- Always use Flux UI components over raw Tailwind/Alpine when available
- Check fluxui.dev docs for component API before building custom markup

## Colors
- Use `gh-*` color tokens from CSS variables, never hardcode hex colors
- RGB-based tokens (bg, surface, border, text, muted, accent, green, red) support Tailwind opacity modifiers: `bg-gh-bg/50`
- Raw tokens (add-bg, del-bg, hunk-bg, etc.) are used directly: `bg-gh-add-bg`
- Theme colors defined in `config/theme.php`

## Dark Mode
- Managed by Flux's `@fluxAppearance` + `$flux.dark` - never hardcode `class="dark"` on `<html>`
- Toggle: `$flux.dark = ! $flux.dark`
- System preference detection is automatic via Flux

## Livewire SFC Components

Page components live in `resources/views/pages/` (namespace `pages::`). Non-page components live in `resources/views/livewire/` (default namespace).

### Parent-Child: Avoid 1+N Re-renders

ReviewPage (`resources/views/pages/⚡review-page.blade.php`) renders N DiffFile children (`resources/views/livewire/⚡diff-file.blade.php`) - one per changed file. Livewire re-hydrates ALL children when a parent re-renders. With `#[Reactive]` props, Livewire's JS interceptor bundles every reactive child into every parent request - even if the prop didn't change. This hits `TooManyComponentsException` (default limit ~20) on repos with many files.

### Rules

1. **Never use `#[Reactive]` on child props** when parent has many children. Data pushed via reactive props causes 1+N hydration on every parent action.

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
| `updatedGlobalComment` | Yes | No UI change needed server-side |
| `toggleViewed` | Yes | Sidebar state managed client-side via Alpine |
| `submitReview` | No | Replaces entire submit bar UI (submitted state) |

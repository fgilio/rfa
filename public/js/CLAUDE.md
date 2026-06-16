# public/js/ Conventions

Loose browser scripts that back Alpine components, Alpine stores, Livewire
custom directives, and global wiring (find-in-page, session recovery, etc.).

## No build process

Scripts here are loaded directly through Blade's versioned `@localScript('js/foo.js')` helper. There is no bundler — no Vite, no esbuild, no transpile.

- Don't `import` from npm packages. Write what runs natively in the target Chromium (Electron 38+).
- Don't use TypeScript.
- If a feature needs npm-only logic, lift it into a Pest-tested PHP path instead.

## Wrap helpers in an IIFE

When a file has local helpers, wrap top-level code in `(function () { ... })();` so those helpers don't leak onto `window`. Anything intentionally global is assigned explicitly (see `window.contextMenuState` in `context-menu.js`).

One-shot wiring with no helpers (e.g. `session-recovery.js`) can skip the IIFE — a bare `document.addEventListener('livewire:init', ...)` is fine.

## Bootstrap via `alpine:init` / `livewire:init`

Don't run setup at script-evaluation time — Alpine and Livewire may not be ready yet. Canonical idiom:

```js
window.Alpine ? init() : document.addEventListener('alpine:init', init);
```

For scripts that touch Livewire (custom directives, request interceptors), use `livewire:init` instead.

## Guard singleton listeners against SPA-navigation re-execution

Livewire's `wire:navigate` swaps the body and re-runs head scripts. Singleton
listeners and stores need a guard so they do not stack on every navigation.
Pattern (see `keymap-store.js`):

```js
if (window.__fooAttached) return;
window.__fooAttached = true;
```

If the script keeps per-page state that doesn't survive navigation (e.g. registered keymap bindings), clear it on `livewire:navigating`.

Alpine factory scripts (`Alpine.data(...)`) should re-register without a
one-shot guard so an updated, cache-busted script replaces stale factories after
an app update.

## Testable scripts use the UMD shape

If a script has logic worth testing (timers, parsers, state machines, custom directives), shape it like `smart-poll.js`:

- IIFE that auto-installs in the browser
- exports its internals when `module.exports` is detected

Tests live in `tests/Js/<name>.test.js` (Vitest + happy-dom). Run with `composer test:js` (or `npm test`). See `tests/CLAUDE.md` for the testing conventions.

## Where new scripts go: public/js vs inline blade

Inline `x-data="{ ... }"` in a blade SFC is fine for trivial component state — a handful of fields, simple toggles, no shared logic. Extract to `public/js/` when any of these hold:

- The factory has non-trivial logic (loops, async, computed getters, Livewire calls beyond `$wire.method()`).
- More than one component needs it (`context-menu.js`, the `smart-poll` directive).
- It's a global concern: an Alpine store, a custom Livewire directive, a request interceptor, a keyboard registry.
- It has logic worth unit-testing — that requires the UMD shape, which requires extraction.

Keep extracted Alpine factories named after the component they back (`branch-explorer.js`, `diff-file.js`); name global concerns after the concern (`keymap-store.js`, `smart-poll.js`).

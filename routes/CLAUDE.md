# Routes

These are internal Livewire-navigate addresses inside the packaged Electron app. The user never sees, types, or bookmarks them. Treat them as plumbing.

- **Adding a route does not expose a page.** A page only becomes reachable when a UI affordance navigates to it (header button, native menu item, deep-link, `./rfa` terminal helper). New entry points belong in `resources/views/pages/` and the components they render — not here.
- **Linking between pages.** From a Livewire component action, use `$this->redirect($url, navigate: true)`. From a Blade template, use `Livewire.navigate('/...')` in an `onclick`. From the main process (`HandleDeepLink`, `HandleMenuItemClicked`), use `Window::get('main')->url(...)`. Never expose a route to the user as something to type.
- **Skip the public-internet assumptions.** No SEO, no auth, no CSRF-across-origins, no rate limiting, no public URLs. The routes file is small on purpose.

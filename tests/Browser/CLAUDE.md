# Browser Tests (Pest Plugin Browser)

- Run: `composer test:browser` (headless) or `composer test:browser:headed` (visible)
- Backed by Playwright (not Selenium) - auto-awaits DOM changes
- `CreatesTestRepo` is wired globally in `tests/Pest.php`
- Each test opts into a repo scenario in `beforeEach`; cleanup is global and automatic
- Assertions auto-retry for ~5 seconds (handles Livewire async)
- `script()` breaks the chain (returns JS result) - capture page reference first
- For Livewire actions: use `pressAndWaitFor()` or assert after `click()` (auto-retry handles it)
- Default repo: `setUpTestRepo()`
- Empty repo: `setUpEmptyTestRepo()`
- Multi-hunk repo: `setUpMultiHunkTestRepo()`
- Commit history repo: `setUpCommitHistoryRepo()`
- Dashboard project list: `setUpRegisteredProjects([...])`
- Use `$this->visitAndLoad($url)` instead of `$this->visit($url)` when the test interacts with lazy-loaded content (e.g. diff line numbers). It waits for pending network activity to settle (Playwright `networkidle`), which covers most mount-time Livewire round-trips and reduces flaky timeouts under load.
- `visitAndLoad()` only waits for `networkidle`, which can fire before a lazy `x-intersect.once` Livewire round-trip has actually injected its DOM. If the test then triggers a synchronous client-side operation that walks the DOM (e.g. find-in-page highlighting) and expects results immediately, add an explicit wait for concrete content (`getByTestId('diff-line-number')->first()->waitFor()` or a text locator) right after `visitAndLoad()`. See `tests/Browser/PageSearchTest.php` for the pattern.
- Prefer `waitFor(['state' => 'hidden'])` over `isVisible()->toBeFalse()` when asserting that an Alpine `x-show` element has closed. `isVisible()` is synchronous and races the DOM update; `waitFor` auto-retries.
- `assertButtonEnabled()` / `assertButtonDisabled()` are **not** auto-retrying — they read Playwright's synchronous `isEnabled()`/`isDisabled()` once. They are safe for a button's initial state, but never straight after a `press()` or `click()` that triggers a Livewire round-trip: the action returns as soon as the click is dispatched, so the assertion races the re-render. Wait for a concrete result of the round-trip first (the saved comment, a changed count), then assert. See `SubmitReviewTest`.
- A bare `getByTestId('diff-line-number')->first()` is an **unstable locator across actions**: locators re-resolve on every action, and while changed files lazy-load their diffs (`x-intersect`) the "first" line on the page flips to whichever file rendered its rows first. If a test clicks a line and later re-acts on the *same* line (e.g. re-opening a draft), `->first()` can resolve to a *different* file the second time. Pin to a named file — `$page->page()->locator('.group:has([data-testid="file-header"]:has-text("hello.php")))')->getByTestId('diff-line-number')->first()` — so both actions hit the same row. See `tests/Browser/DraftCommentTest.php` 'clicking line with existing draft re-opens it'.
- Escape the colon in `wire:id` attribute selectors: `querySelectorAll('[wire\\:id]')` (JS-source double backslash). An unescaped `[wire:id]` throws `SyntaxError`. Never wrap such a selector in a `try { ... } catch { return false }` poll — a thrown selector silently degrades the "wait" into a fixed timeout-length sleep, which reads as a deterministic wait but isn't (this is exactly what once left the draft re-open test flaky). Better still, don't select by `wire:id` at all — resolve a component id via `querySelector('[data-testid="…"]').getAttribute('wire:id')`. Prefer a `page()->waitForFunction()` predicate that fails loudly on a bad selector.

The recurring ones above are enforced by `tests/Arch/BrowserTestConventionsTest.php` (runs in the fast Core suite): no `->flaky()`, no `networkidle` barrier in tests, no `isVisible()->toBeFalse()/toBeTrue()`, no selecting by `[wire:id]`, and no assigning an unscoped page-level `diff-line-number` locator to a variable. Add a rule there when you find a new flake class.

## Selector Priority

1. **Semantic locators** - `$page->page()->getByRole()`, `getByLabel()`, `getByPlaceholder()` for buttons, labeled controls, form fields
2. **`data-testid`** - `$page->page()->getByTestId()` for non-semantic elements (diff line cells, structural containers)
3. **CSS selectors** - last resort, only for structural queries with no semantic alternative

Naming convention for `data-testid`: `<scope>-<element>` (e.g. `diff-line-number`, `file-header`, `review-component`).

Use locator chaining for repeated elements: `->first()`, `->nth(0)`, `->last()` instead of `querySelectorAll()[0]`.

Access the Playwright Page via `$page->page()` to use semantic locators. Keep using `$page->press()`, `$page->assertSee()` etc. for text-based interactions and assertions.

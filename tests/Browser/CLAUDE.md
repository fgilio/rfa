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

## Selector Priority

1. **Semantic locators** - `$page->page()->getByRole()`, `getByLabel()`, `getByPlaceholder()` for buttons, labeled controls, form fields
2. **`data-testid`** - `$page->page()->getByTestId()` for non-semantic elements (diff line cells, structural containers)
3. **CSS selectors** - last resort, only for structural queries with no semantic alternative

Naming convention for `data-testid`: `<scope>-<element>` (e.g. `diff-line-number`, `file-header`, `review-component`).

Use locator chaining for repeated elements: `->first()`, `->nth(0)`, `->last()` instead of `querySelectorAll()[0]`.

Access the Playwright Page via `$page->page()` to use semantic locators. Keep using `$page->press()`, `$page->assertSee()` etc. for text-based interactions and assertions.

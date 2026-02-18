# Browser Tests (Pest Plugin Browser)

- Run: `composer test:browser` (headless) or `composer test:browser:headed` (visible)
- Backed by Playwright (not Selenium) - auto-awaits DOM changes
- Uses `CreatesTestRepo` trait to create temp git repos with known diffs
- Each test gets a fresh repo in `beforeEach`, cleaned up in `afterEach`
- Assertions auto-retry for ~5 seconds (handles Livewire async)
- `script()` breaks the chain (returns JS result) - capture page reference first
- For Livewire actions: use `pressAndWaitFor()` or assert after `click()` (auto-retry handles it)
- EmptyStateTest uses `setUpEmptyTestRepo()` instead of `setUpTestRepo()`

## Selector Priority

1. **Semantic locators** - `$page->page()->getByRole()`, `getByLabel()`, `getByPlaceholder()` for buttons, labeled controls, form fields
2. **`data-testid`** - `$page->page()->getByTestId()` for non-semantic elements (diff line cells, structural containers)
3. **CSS selectors** - last resort, only for structural queries with no semantic alternative

Naming convention for `data-testid`: `<scope>-<element>` (e.g. `diff-line-number`, `file-header`, `review-component`).

Use locator chaining for repeated elements: `->first()`, `->nth(0)`, `->last()` instead of `querySelectorAll()[0]`.

Access the Playwright Page via `$page->page()` to use semantic locators. Keep using `$page->press()`, `$page->assertSee()` etc. for text-based interactions and assertions.

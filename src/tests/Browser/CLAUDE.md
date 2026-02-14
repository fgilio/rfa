# Browser Tests (Pest Plugin Browser)

- Run: `composer test:browser` (headless) or `composer test:browser:headed` (visible)
- Backed by Playwright (not Selenium) - auto-awaits DOM changes
- Uses `CreatesTestRepo` trait to create temp git repos with known diffs
- Each test gets a fresh repo in `beforeEach`, cleaned up in `afterEach`
- Assertions auto-retry for ~5 seconds (handles Livewire async)
- `script()` breaks the chain (returns JS result) - capture page reference first
- For Livewire actions: use `pressAndWaitFor()` or assert after `click()` (auto-retry handles it)
- Keep selectors high-level: text content, CSS classes, placeholder text
- EmptyStateTest uses `setUpEmptyTestRepo()` instead of `setUpTestRepo()`

# Screenshot Tests

Pest browser tests whose only job is to capture named PNGs of UI states.
Surfaces them as inline images in PR comments via the `Screenshots` GitHub
Actions workflow.

## Adding screenshots for a PR

1. Add a `*ScreenshotTest.php` file here (or extend an existing one) for the
   UI area you're changing.
2. Drive the page to the state you want to capture, then call
   `$page->page()->screenshot(false, 'descriptive-name')`. Use `false` for
   viewport-only (better for inline preview); `true` for full-page.
3. Run `composer test:screenshots` locally to verify. PNGs land in
   `tests/Browser/Screenshots/` (hardcoded by the Pest plugin).
4. Push. The `Screenshots` workflow runs on the PR, commits the PNGs to the
   `screenshots` orphan branch under `pr-<number>/<sha>/`, and updates the
   sticky PR comment.

## Conventions

- One file per touched UI area; `<Feature>ScreenshotTest.php`.
- Filenames passed to `screenshot()` are kebab-case and scoped by feature
  (`copy-paths-bulk-menu-open`, not just `menu-open`). They appear verbatim
  in the comment.
- Wait for a deterministic landmark (`->waitFor()` on a locator that only
  exists once the state has settled) **before** capturing — otherwise you
  get flaky screenshots of half-rendered UI.
- Keep the suite fast: each test pays the `setUpTestRepo()` tax. Capture
  multiple shots within a single test when they share setup.
- Excluded from `composer test` and `composer test:browser`. Run via
  `composer test:screenshots`.

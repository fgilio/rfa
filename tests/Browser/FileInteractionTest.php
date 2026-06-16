<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

/**
 * Poll the clipboard text captured by a `copy-to-clipboard` window listener.
 * Bulk copy is a server round-trip, and the Pest-browser waitForFunction is a
 * single evaluate (not a poll), so wait from PHP for the dispatched event.
 */
function waitForCopiedText($page, float $timeoutSec = 5.0): ?string
{
    $deadline = microtime(true) + $timeoutSec;

    while (microtime(true) < $deadline) {
        $text = $page->script('window.__copiedText');
        if ($text !== null) {
            return $text;
        }
        usleep(100_000);
    }

    return null;
}

function waitForReviewedCounter($page, string $text): void
{
    $counter = $page->page()->getByTestId('reviewed-counter');

    $counter->getByText($text)->waitFor(['state' => 'visible']);

    expect($counter->innerText())->toContain($text);
}

function clickFirstUncheckedReviewedCheckbox($page): void
{
    $page->page()
        ->locator('[data-rfa-diff-file] ui-checkbox:not([data-checked])')
        ->first()
        ->click();
}

function cssRgbChannelValues(string $rgb): array
{
    preg_match_all('/[\d.]+/', $rgb, $matches);

    return array_map('floatval', array_slice($matches[0], 0, 3));
}

function cssContrastRatio(string $firstColor, string $secondColor): float
{
    $relativeLuminance = function (string $color): float {
        $channels = array_map(
            fn (float $channel): float => $channel / 255,
            cssRgbChannelValues($color),
        );

        $linear = array_map(
            fn (float $channel): float => $channel <= 0.03928
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };

    $first = $relativeLuminance($firstColor);
    $second = $relativeLuminance($secondColor);

    return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
}

function renderedDiffFiles($page): array
{
    return $page->script("
        [...document.querySelectorAll('[data-rfa-diff-file]')].map((element) => {
            const data = window.Alpine?.\$data(element) ?? {};
            const checkbox = element.querySelector('ui-checkbox');

            return {
                id: element.getAttribute('data-file-id'),
                path: data.filePath ?? null,
                checked: checkbox?.checked ?? null,
                dataChecked: checkbox?.hasAttribute('data-checked') ?? null,
            };
        })
    ");
}

function waitForRenderedDiffFileCount($page, int $expectedCount, float $timeoutSec = 5.0): array
{
    $deadline = microtime(true) + $timeoutSec;
    $diffFiles = [];

    while (microtime(true) < $deadline) {
        $diffFiles = renderedDiffFiles($page);

        if (count($diffFiles) === $expectedCount) {
            return $diffFiles;
        }

        usleep(100_000);
    }

    return $diffFiles;
}

test('clicking file name collapses and expands file diff', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee("'debug'");

    // Click the file name text to collapse
    $page->page()->getByTestId('file-header')->first()->getByText('config.php')->click();

    $page->assertDontSee("'debug'");

    // Click again to expand
    $page->page()->getByTestId('file-header')->first()->getByText('config.php')->click();

    $page->assertSee("'debug'");
});

test('alt+click chevron collapses all files', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee('function greet');

    $page->page()->getByLabel('Collapse file')->first()->click(['modifiers' => ['Alt']]);

    $page->assertDontSee('function greet');
});

test('alt+click chevron expands all files', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee('function greet');

    // Collapse all first
    $page->page()->getByLabel('Collapse file')->first()->click(['modifiers' => ['Alt']]);
    $page->assertDontSee('function greet');

    // Alt+click collapsed chevron to expand ALL
    $page->page()->getByLabel('Expand file')->first()->click(['modifiers' => ['Alt']]);

    $page->assertSee('function greet');
});

test('alt+click file name collapses all files', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee('function greet');

    $page->page()->getByTestId('file-header')->first()->getByText('config.php')->click(['modifiers' => ['Alt']]);

    $page->assertDontSee('function greet');
});

test('alt+click file name expands all files', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee('function greet');

    // Collapse all first
    $page->page()->getByTestId('file-header')->first()->getByText('config.php')->click(['modifiers' => ['Alt']]);
    $page->assertDontSee('function greet');

    // Alt+click again to expand ALL
    $page->page()->getByTestId('file-header')->first()->getByText('config.php')->click(['modifiers' => ['Alt']]);

    $page->assertSee('function greet');
});

test('clicking toggle zone gap collapses and expands file', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee("'debug'");

    // Get toggle zone dimensions so we can click the empty space past the filename
    $page->script("
        window.__zoneDims = (function() {
            var el = document.querySelector('[data-testid=\"toggle-zone\"]');
            var r = el.getBoundingClientRect();
            return [r.width, r.height];
        })();
    ");
    $dims = $page->script('window.__zoneDims');

    // Click near the right edge of the toggle zone (gap area, not filename text)
    $page->page()->getByTestId('toggle-zone')->first()
        ->click(['position' => ['x' => intval($dims[0]) - 5, 'y' => intval($dims[1] / 2)]]);

    $page->assertDontSee("'debug'");

    // Click gap again to expand
    $page->page()->getByTestId('toggle-zone')->first()
        ->click(['position' => ['x' => intval($dims[0]) - 5, 'y' => intval($dims[1] / 2)]]);

    $page->assertSee("'debug'");
});

test('copy button click does not collapse file', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee("'debug'");

    $page->page()->getByTestId('file-header-copy-path-trigger')->first()->click();

    // Wait past the collapse animation duration (150ms)
    usleep(300_000);

    $page->assertSee("'debug'");
});

test('copy file path button dispatches copy event', function () {
    $page = $this->visit($this->projectUrl());

    // Listen for the copy-to-clipboard event
    $page->script("
        window.__copiedText = null;
        window.addEventListener('copy-to-clipboard', e => window.__copiedText = e.detail.text);
    ");

    $page->page()->getByTestId('file-header-copy-path-trigger')->first()->click();

    $result = $page->script('window.__copiedText');
    expect($result)->not->toBeNull();
});

test('copy content dropdown is visible for text files', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // The dropdown trigger should be present for text files
    $page->page()->getByLabel('Copy content')->first()->waitFor();
    $count = $page->page()->getByLabel('Copy content')->count();
    expect($count)->toBeGreaterThan(0);

    // Open the dropdown and verify menu items
    $page->page()->getByLabel('Copy content')->first()->click();
    $page->page()->getByRole('menuitem', ['name' => 'Copy diff'])->first()->waitFor();
    $page->page()->getByRole('menuitem', ['name' => 'Copy original'])->first()->waitFor();
    $page->page()->getByRole('menuitem', ['name' => 'Copy new'])->first()->waitFor();
});

test('clicking chevron collapses and expands file diff', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee('return [');

    // Collapse the first file (config.php)
    $page->page()->getByLabel('Collapse file')->first()->click();

    // config.php content should be hidden after collapse
    $page->assertDontSee("'debug'");

    // Expand again
    $page->page()->getByLabel('Expand file')->first()->click();

    $page->assertSee("'debug'");
});

test('shift+c collapses all files', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee('function greet');

    $this->pressGlobalKey($page, 'C', ['shiftKey' => true]);

    // Auto-retries ~5s (avoids one-shot check during CSS transition)
    $page->assertDontSee('function greet');
});

test('shift+e expands all files', function () {
    $page = $this->visit($this->projectUrl());

    // Wait for lazy-loaded diff to appear before collapsing
    $page->assertSee('function greet');

    // Collapse all first
    $this->pressGlobalKey($page, 'C', ['shiftKey' => true]);

    // Wait for collapse to complete (auto-retries ~5s)
    $page->assertDontSee('function greet');

    // Expand all
    $this->pressGlobalKey($page, 'E', ['shiftKey' => true]);

    $page->assertSee('function greet');
});

test('checking reviewed updates sidebar indicator', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->first()->click();

    waitForReviewedCounter($page, '1/3 reviewed');
});

test('a second mark in normal mode advances the status-strip counter via the slot island', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // First mark: 1/3. Second mark: 2/3. The counter lives in the
    // reviewed-counter island nested inside the x-status-strip slot, refreshed
    // by renderIsland on the skipRender path. This guards that the slot-scoped
    // island actually re-renders (not just the first mark or the hide-mode
    // full render).
    clickFirstUncheckedReviewedCheckbox($page);
    waitForReviewedCounter($page, '1/3 reviewed');

    clickFirstUncheckedReviewedCheckbox($page);
    waitForReviewedCounter($page, '2/3 reviewed');
});

test('marking a file flips its sidebar button to the reviewed state via the island', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // Mark via the diff-file checkbox; the sidebar button is server-rendered in
    // the file-list island and must re-render into its un-mark state.
    $page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->first()->click();

    $page->page()->getByRole('button', ['name' => 'Un-mark as reviewed'])->first()->waitFor(['state' => 'visible']);
});

test('marking a file reveals the Hide reviewed toggle', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // The toggle lives in the reviewed-toggle island and is absent until at
    // least one file is reviewed.
    $page->page()->getByRole('button', ['name' => 'Hide reviewed'])->waitFor(['state' => 'hidden']);

    $page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->first()->click();

    // The mark refreshes the island, which renders the toggle into view.
    $page->page()->getByRole('button', ['name' => 'Hide reviewed'])->waitFor(['state' => 'visible']);
});

test('checked reviewed checkbox keeps its check glyph visible in dark mode', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("
        document.documentElement.classList.add('dark');
        if (window.Flux) window.Flux.dark = true;
    ");

    $page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->first()->click();
    waitForReviewedCounter($page, '1/3 reviewed');

    $styles = $page->script("
        (() => {
            const checkboxes = [...document.querySelectorAll('ui-checkbox')];
            const checkbox = checkboxes.find((candidate) => candidate.checked);
            const indicator = checkbox?.querySelector('[data-flux-checkbox-indicator]');
            const icon = indicator?.querySelector('svg');

            return {
                checked: Boolean(checkbox),
                hasCheckedAttribute: checkbox?.hasAttribute('data-checked') ?? false,
                display: icon ? getComputedStyle(icon).display : null,
                iconColor: icon ? getComputedStyle(icon).color : null,
                backgroundColor: indicator ? getComputedStyle(indicator).backgroundColor : null,
            };
        })()
    ");

    expect($styles['checked'])->toBeTrue()
        ->and($styles['hasCheckedAttribute'])->toBeTrue()
        ->and($styles['display'])->toBe('block')
        ->and(cssContrastRatio($styles['iconColor'], $styles['backgroundColor']))->toBeGreaterThan(3.0);
});

test('hide reviewed removes checked diff files from the rendered page', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $markedPath = waitForRenderedDiffFileCount($page, 3)[0]['path'];

    $page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->first()->click();
    $page->page()->getByRole('button', ['name' => 'Hide reviewed'])->waitFor(['state' => 'visible']);
    $page->page()->getByRole('button', ['name' => 'Hide reviewed'])->click();

    $page->page()->getByRole('button', ['name' => 'Show all files'])->waitFor(['state' => 'visible']);
    $diffFiles = waitForRenderedDiffFileCount($page, 2);

    expect($diffFiles)->toHaveCount(2);
    expect(collect($diffFiles)->pluck('path')->all())->not->toContain($markedPath);
    expect($page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->count())->toBe(2);

    // The status-strip count band renders outside the islands the toggle
    // refreshes, so it must drop to the visible total via its own island rather
    // than stay stale at "3 files".
    $page->page()->getByTestId('status-strip')->getByText('2/3 files')->waitFor(['state' => 'visible']);

    $page->page()->getByRole('button', ['name' => 'Show all files'])->click();
    $diffFiles = waitForRenderedDiffFileCount($page, 3);

    expect($diffFiles)->toHaveCount(3);
    expect(collect($diffFiles)->pluck('path')->all())->toContain($markedPath);

    // Showing all files clears the partial-visibility count.
    $page->page()->getByTestId('status-strip')->getByText('2/3 files')->waitFor(['state' => 'hidden']);
});

test('status strip copy menu hides when hide-reviewed removes every file', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    foreach (range(0, 2) as $index) {
        clickFirstUncheckedReviewedCheckbox($page);
        waitForReviewedCounter($page, ($index + 1).'/3 reviewed');
    }

    $page->page()->getByRole('button', ['name' => 'Hide reviewed'])->click();

    $page->page()->getByTestId('status-strip-copy-paths')->waitFor(['state' => 'hidden']);

    expect($page->page()->getByTestId('status-strip-copy-paths')->isHidden())->toBeTrue();
});

test('clicking sidebar file scrolls to it', function () {
    $page = $this->visit($this->projectUrl());

    // Click the last sidebar button (utils.php)
    $page->page()->getByRole('button', ['name' => 'utils.php'])->click();

    // The clicked file gets the active-row highlight (bg-gh-text/10). waitFor
    // first — the active class lands after the click's Alpine tick, so a bare
    // synchronous count() races it.
    $active = $page->page()->locator('aside [class*="bg-gh-text/10"]');
    $active->first()->waitFor();
    expect($active->count())->toBeGreaterThan(0);
});

test('file comment button opens form and save displays comment', function () {
    $page = $this->visit($this->projectUrl());

    // Click file comment button
    $page->page()->getByLabel('Add file comment')->first()->click();

    // File comment form opens with Cancel/Save buttons
    $page->assertSee('Cancel');

    $page->page()->getByPlaceholder('File comment', false)->fill('File-level note');
    $page->press('Save');

    $page->assertSee('File-level note');
});

test('clicking delete x removes a comment', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Delete me');
    $page->press('Save');
    $page->assertSee('Delete me');

    $page->page()->getByLabel('Delete comment')->first()->click();

    $page->assertDontSee('Delete me');
});

test('sidebar copy paths trigger left-click copies relative paths', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("
        window.__copiedText = null;
        window.addEventListener('copy-to-clipboard', e => window.__copiedText = e.detail.text);
    ");

    $page->page()->getByTestId('sidebar-copy-paths-trigger')->click();

    // Bulk copy is a server round-trip now. The Pest-browser waitForFunction is a
    // single evaluate, not a poll, so poll the captured text from PHP instead.
    $result = waitForCopiedText($page);
    expect($result)->not->toBeNull();
    expect(explode("\n", $result))->toEqualCanonicalizing(['hello.php', 'utils.php', 'config.php']);
});

test('sidebar copy paths right-click opens menu with file-name option', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("
        window.__copiedText = null;
        window.addEventListener('copy-to-clipboard', e => window.__copiedText = e.detail.text);
    ");

    $page->page()->getByTestId('sidebar-copy-paths-trigger')->click(['button' => 'right']);
    $page->page()->getByRole('menuitem', ['name' => 'Copy 3 file names'])->first()->click();

    $result = waitForCopiedText($page);
    expect($result)->not->toBeNull();
    expect(explode("\n", $result))->toEqualCanonicalizing(['hello.php', 'utils.php', 'config.php']);
});

test('status strip copy paths trigger left-click copies relative paths', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("
        window.__copiedText = null;
        window.addEventListener('copy-to-clipboard', e => window.__copiedText = e.detail.text);
    ");

    $page->page()->getByTestId('status-strip-copy-paths-trigger')->click();

    $result = waitForCopiedText($page);
    expect($result)->not->toBeNull();
    expect(explode("\n", $result))->toEqualCanonicalizing(['hello.php', 'utils.php', 'config.php']);
});

test('right-click copy full paths prepends repo path to each entry', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script("
        window.__copiedText = null;
        window.addEventListener('copy-to-clipboard', e => window.__copiedText = e.detail.text);
    ");

    $page->page()->getByTestId('sidebar-copy-paths-trigger')->click(['button' => 'right']);
    $page->page()->getByRole('menuitem', ['name' => 'Copy 3 full paths'])->first()->click();

    $result = waitForCopiedText($page);
    expect($result)->not->toBeNull();

    $expectedRepoPath = realpath($this->testRepoPath) ?: $this->testRepoPath;

    collect(explode("\n", $result))
        ->each(fn (string $line) => expect($line)->toStartWith($expectedRepoPath.'/'));
});

test('copy menu hides when filter excludes all files', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByPlaceholder('Filter files...')->fill('zzz-no-such-file');

    $page->page()->getByTestId('sidebar-copy-paths')->waitFor(['state' => 'hidden']);
    $page->page()->getByTestId('status-strip-copy-paths')->waitFor(['state' => 'hidden']);

    expect($page->page()->getByTestId('sidebar-copy-paths')->isHidden())->toBeTrue();
    expect($page->page()->getByTestId('status-strip-copy-paths')->isHidden())->toBeTrue();
});

<?php

/**
 * Guards against the browser-test flake patterns we have actually had to fix.
 * Each rule below corresponds to a real flake (or its proven root cause) so the
 * same mistake fails fast in CI instead of resurfacing as an intermittent red.
 *
 * See tests/Browser/CLAUDE.md for the prose version and the proven replacements.
 *
 * These scan the source of the browser test FILES (not the helpers): the only
 * sanctioned networkidle lives in CreatesTestRepo::visitAndLoad, which is under
 * Helpers/ and therefore excluded.
 */

/**
 * @return array<int, array{file: string, content: string}>
 */
function browserTestSources(): array
{
    $dir = dirname(__DIR__, 2).'/tests/Browser';
    $sources = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        // Test files only — skip the Helpers/ subtree (CreatesTestRepo owns the
        // one sanctioned networkidle wait) and any non-PHP file.
        if ($file->getExtension() !== 'php') {
            continue;
        }
        if (str_contains($file->getPathname(), '/Helpers/')) {
            continue;
        }
        $sources[] = [
            'file' => str_replace($dir.'/', '', $file->getPathname()),
            'content' => (string) file_get_contents($file->getPathname()),
        ];
    }

    return $sources;
}

/**
 * Collect "<file>:<line>" hits for every line matching $pattern.
 *
 * @return array<int, string>
 */
function browserTestViolations(string $pattern): array
{
    $violations = [];
    foreach (browserTestSources() as $source) {
        $lines = explode("\n", $source['content']);
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line)) {
                $violations[] = $source['file'].':'.($index + 1).'  '.trim($line);
            }
        }
    }

    return $violations;
}

test('browser tests do not use ->flaky() to mask races', function () {
    // ->flaky() retries a test up to N times, hiding the underlying race instead
    // of fixing it. Find the root cause and remove the retry (see the
    // SessionPersistence/DraftComment fixes for the proven replacements).
    expect(browserTestViolations('/->flaky\(/'))->toBeEmpty();
});

test('browser tests do not wait on networkidle as a Livewire-commit barrier', function () {
    // waitForLoadState('networkidle') resolves the instant the network is quiet —
    // which can be BEFORE Livewire dispatches a commit POST (it goes out on a later
    // tick) and before a lazy x-intersect round-trip injects its DOM. Reading
    // persisted/async state after it races the real work. Use the commit-lifecycle
    // hook + waitForFunction (server state) or a locator ->waitFor() (DOM), per
    // tests/Browser/CLAUDE.md. The only sanctioned networkidle is in
    // CreatesTestRepo::visitAndLoad (the initial-load settle), which is excluded here.
    expect(browserTestViolations("/->waitFor(?:Event|LoadState)\\(\\s*['\"]networkidle['\"]/"))->toBeEmpty();
});

test('browser tests assert visibility with waitFor, not synchronous isVisible()->toBe*()', function () {
    // isVisible() is a one-shot synchronous read; chaining ->toBeFalse()/->toBeTrue()
    // races the async DOM update. Prefer waitFor(['state' => 'hidden'|'visible']),
    // which auto-retries.
    expect(browserTestViolations('/isVisible\\(\\)\\s*\\)?\\s*->\\s*toBe(?:False|True)\\(/'))->toBeEmpty();
});

test('browser tests do not select elements by the wire:id attribute', function () {
    // querySelectorAll('[wire:id]') needs the colon escaped ('[wire\:id]'); the
    // unescaped form throws SyntaxError. When such a selector is buried in a
    // try/catch poll the throw is swallowed and the "wait" silently degrades into a
    // fixed-length sleep (this is what once hid the DraftComment re-open flake).
    // Resolve a component id the robust way instead:
    //   querySelector('[data-testid="…"]').getAttribute('wire:id')
    expect(browserTestViolations("/querySelector(?:All)?\\(\\s*['\"]\\[wire/"))->toBeEmpty();
});

test('browser tests scope a reusable diff-line-number locator to a file', function () {
    // $page->page()->getByTestId('diff-line-number')->first() assigned to a variable
    // is an UNSTABLE locator: it re-resolves on every action, and while files
    // lazy-load their diffs the "first" line on the page flips between files. A test
    // that clicks the line and later re-acts on it can hit a different file the second
    // time (the DraftComment re-open flake). Scope the locator to one named file —
    //   $file = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("hello.php"))');
    //   $lineNum = $file->getByTestId('diff-line-number')->first();
    // so both actions resolve to the same row. (Inline single-use is fine; this only
    // forbids assigning the unscoped, page-level locator to a variable.)
    $violations = browserTestViolations(
        '/\$\w+\s*=\s*\$\w+->page\(\)->getByTestId\(\s*[\'"]diff-line-number[\'"]\s*\)->(?:first|nth|last)\(/'
    );
    expect($violations)->toBeEmpty();
});

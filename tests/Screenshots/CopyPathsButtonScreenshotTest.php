<?php

// PR #87 — unified copy-paths button. Captures the bulk and single-file
// triggers in their default state and with the right-click menu open.
//
// Output lands in tests/Browser/Screenshots/ (hardcoded by Pest's browser
// plugin). The screenshots CI workflow publishes those PNGs to the
// `screenshots` orphan branch and links them from a sticky PR comment.
//
// Note: must call the global `visit()` helper (not $this->visit) so Pest's
// BrowserTestIdentifier picks these up as browser tests outside tests/Browser/.

beforeEach(function () {
    $this->setUpTestRepo();
});

test('copy-paths bulk trigger — default and menu open', function () {
    $page = visit($this->projectUrl());
    $page->waitForEvent('networkidle');

    $page->page()->getByTestId('sidebar-copy-paths-trigger')->waitFor();
    $page->page()->screenshot(false, 'copy-paths-bulk-default');

    $page->page()->getByTestId('sidebar-copy-paths-trigger')->click(['button' => 'right']);
    $page->page()->getByRole('menuitem', ['name' => 'Copy file names'])->first()->waitFor();
    $page->page()->screenshot(false, 'copy-paths-bulk-menu-open');
});

test('copy-paths single-file trigger — menu open', function () {
    $page = visit($this->projectUrl());
    $page->waitForEvent('networkidle');

    $page->page()->getByTestId('file-header-copy-path-trigger')->first()->waitFor();
    $page->page()->getByTestId('file-header-copy-path-trigger')->first()->click(['button' => 'right']);
    $page->page()->getByRole('menuitem', ['name' => 'Copy full paths'])->first()->waitFor();
    $page->page()->screenshot(false, 'copy-paths-single-menu-open');
});

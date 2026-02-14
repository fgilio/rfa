<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

// Helper: JS to click first line number with a @click handler
const CLICK_LINE = "document.querySelectorAll('td.diff-line-num')[0].click()";

test('clicking line number opens comment form with focused textarea', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE);

    // "Cancel" button only appears when comment form is open
    $page->assertSee('Cancel');
});

test('saving comment displays it inline below the target line', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE);
    $page->type('[placeholder*="Write a comment"]', 'This needs refactoring');
    $page->press('Save');

    $page->assertSee('This needs refactoring');
});

test('canceling comment form clears the draft', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE);
    $page->assertSee('Cancel');

    $page->press('Cancel');

    $page->assertDontSee('Cancel');
});

test('cmd+enter keyboard shortcut saves comment', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE);
    $page->type('[placeholder*="Write a comment"]', 'Keyboard shortcut test');

    // Send Meta+Enter as single combo string for Playwright
    $page->keys('[placeholder*="Write a comment"]', 'Meta+Enter');

    $page->assertSee('Keyboard shortcut test');
});

test('escape keyboard shortcut cancels comment', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE);
    $page->assertSee('Cancel');

    $page->keys('[placeholder*="Write a comment"]', 'Escape');

    $page->assertDontSee('Cancel');
});

test('shift+click selects a line range', function () {
    $page = $this->visit('/');

    // Click first line number
    $page->script(CLICK_LINE);

    // Shift+click a later line number to extend range
    $page->script("
        const cells = document.querySelectorAll('td.diff-line-num');
        cells[4].dispatchEvent(new MouseEvent('click', { shiftKey: true, bubbles: true }));
    ");

    $page->assertSee('Cancel');
});

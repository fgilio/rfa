<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

const CLICK_LINE_FILE = "document.querySelectorAll('td.diff-line-num')[0].click()";

test('clicking chevron collapses and expands file diff', function () {
    $page = $this->visit('/');

    $page->assertSee('return [');

    // Collapse the first file (config.php) - unique text: 'debug'
    $page->script("document.querySelector('.group button').click()");

    // config.php content should be hidden after collapse
    $page->assertDontSee("'debug'");

    // Expand again
    $page->script("document.querySelector('.group button').click()");

    $page->assertSee("'debug'");
});

test('shift+c collapses all files', function () {
    $page = $this->visit('/');

    $page->assertSee('function greet');

    $page->script("
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'C', shiftKey: true, bubbles: true
        }));
    ");

    // Auto-retries ~5s (avoids one-shot check during CSS transition)
    $page->assertDontSee('function greet');
});

test('shift+e expands all files', function () {
    $page = $this->visit('/');

    // Collapse all first
    $page->script("
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'C', shiftKey: true, bubbles: true
        }));
    ");

    // Wait for collapse to complete (auto-retries ~5s)
    $page->assertDontSee('function greet');

    // Expand all
    $page->script("
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'E', shiftKey: true, bubbles: true
        }));
    ");

    $page->assertSee('function greet');
});

test('checking viewed updates sidebar indicator', function () {
    $page = $this->visit('/');

    // Click the first Flux ui-checkbox element (Viewed checkbox)
    $page->script("document.querySelector('ui-checkbox').click()");

    $page->assertSee('1/3 viewed');
});

test('clicking sidebar file scrolls to it', function () {
    $page = $this->visit('/');

    // Click the last sidebar button (utils.php)
    $page->script("
        const buttons = document.querySelectorAll('aside button');
        buttons[buttons.length - 1].click();
    ");

    $hasActive = $page->script("
        [...document.querySelectorAll('aside button')].some(b => b.classList.contains('text-gh-accent'))
    ");
    expect($hasActive)->toBeTrue();
});

test('file comment button opens form and save displays comment', function () {
    $page = $this->visit('/');

    // Click file comment button - it's the button with ml-2 class in the first file header
    $page->script("
        const header = document.querySelector('.group .sticky');
        const buttons = header.querySelectorAll('button');
        buttons[buttons.length - 1].click();
    ");

    // File comment form opens with Cancel/Save buttons
    $page->assertSee('Cancel');

    $page->type('[placeholder*="File comment"]', 'File-level note');
    $page->press('Save');

    $page->assertSee('File-level note');
});

test('clicking delete x removes a comment', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE_FILE);
    $page->type('[placeholder*="Write a comment"]', 'Delete me');
    $page->press('Save');
    $page->assertSee('Delete me');

    $page->script("document.querySelector('.comment-indicator button').click()");

    $page->assertDontSee('Delete me');
});

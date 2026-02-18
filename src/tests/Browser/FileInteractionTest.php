<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

test('clicking chevron collapses and expands file diff', function () {
    $page = $this->visit('/');

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

    // Wait for lazy-loaded diff to appear before collapsing
    $page->assertSee('function greet');

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

    $page->page()->getByLabel('Viewed')->first()->click();

    $page->assertSee('1/3 viewed');
});

test('clicking sidebar file scrolls to it', function () {
    $page = $this->visit('/');

    // Click the last sidebar button (utils.php)
    $page->page()->getByRole('button', ['name' => 'utils.php'])->click();

    $activeCount = $page->page()->locator('aside button.text-gh-accent')->count();
    expect($activeCount)->toBeGreaterThan(0);
});

test('file comment button opens form and save displays comment', function () {
    $page = $this->visit('/');

    // Click file comment button
    $page->page()->getByLabel('Add file comment')->first()->click();

    // File comment form opens with Cancel/Save buttons
    $page->assertSee('Cancel');

    $page->page()->getByPlaceholder('File comment', false)->fill('File-level note');
    $page->press('Save');

    $page->assertSee('File-level note');
});

test('clicking delete x removes a comment', function () {
    $page = $this->visit('/');

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Delete me');
    $page->press('Save');
    $page->assertSee('Delete me');

    $page->page()->getByLabel('Delete comment')->first()->click();

    $page->assertDontSee('Delete me');
});

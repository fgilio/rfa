<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

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

test('copy file name button dispatches copy event', function () {
    $page = $this->visit($this->projectUrl());

    // Listen for the copy-to-clipboard event
    $page->script("
        window.__copiedText = null;
        window.addEventListener('copy-to-clipboard', e => window.__copiedText = e.detail.text);
    ");

    $page->page()->getByLabel('Copy file name')->first()->click();

    $result = $page->script('window.__copiedText');
    expect($result)->not->toBeNull();
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

    $page->script("
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'C', shiftKey: true, bubbles: true
        }));
    ");

    // Auto-retries ~5s (avoids one-shot check during CSS transition)
    $page->assertDontSee('function greet');
});

test('shift+e expands all files', function () {
    $page = $this->visit($this->projectUrl());

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
    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('Viewed')->first()->click();

    $page->assertSee('1/3 viewed');
});

test('clicking sidebar file scrolls to it', function () {
    $page = $this->visit($this->projectUrl());

    // Click the last sidebar button (utils.php)
    $page->page()->getByRole('button', ['name' => 'utils.php'])->click();

    $activeCount = $page->page()->locator('aside button.text-gh-accent')->count();
    expect($activeCount)->toBeGreaterThan(0);
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

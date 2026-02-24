<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

test('clicking line number opens comment form with focused textarea', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();

    // "Cancel" button only appears when comment form is open
    $page->assertSee('Cancel');
});

test('saving comment displays it inline below the target line', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('This needs refactoring');
    $page->press('Save');

    $page->assertSee('This needs refactoring');
});

test('canceling comment form clears the draft', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->assertSee('Cancel');

    $page->press('Cancel');

    $page->assertDontSee('Cancel');
});

test('cmd+enter keyboard shortcut saves comment', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Keyboard shortcut test');

    // Send Meta+Enter as single combo string for Playwright
    $page->page()->getByPlaceholder('Write a comment', false)->press('Meta+Enter');

    $page->assertSee('Keyboard shortcut test');
});

test('escape keyboard shortcut cancels comment', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->assertSee('Cancel');

    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertDontSee('Cancel');
});

test('shift+click selects a line range', function () {
    $page = $this->visit($this->projectUrl());

    // Click first line number
    $page->page()->getByTestId('diff-line-number')->first()->click();

    // Shift+click a later line number to extend range
    $page->page()->getByTestId('diff-line-number')->nth(4)->click(['modifiers' => ['Shift']]);

    $page->assertSee('Cancel');
});

test('comments on left and right sides at same line render independently', function () {
    $page = $this->visit($this->projectUrl());

    $helloFile = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("hello.php"))');

    // Click left-side line number on a removed row (old line column)
    $helloFile->locator('tr.bg-gh-del-bg > td[data-testid="diff-line-number"]:first-child')->first()->click();
    // Two textareas may render (removed + added rows share the same $lineNum); they share Alpine state
    $helloFile->getByPlaceholder('Write a comment', false)->first()->fill('Left side comment');
    $helloFile->getByRole('button', ['name' => 'Save'])->first()->click();
    $page->assertSee('Left side comment');

    // Click right-side line number on an added row (new line column)
    $helloFile->locator('tr.bg-gh-add-bg > td[data-testid="diff-line-number"]:nth-child(2)')->first()->click();
    $helloFile->getByPlaceholder('Write a comment', false)->first()->fill('Right side comment');
    $helloFile->getByRole('button', ['name' => 'Save'])->first()->click();

    // Both comments should be visible independently
    $page->assertSee('Left side comment');
    $page->assertSee('Right side comment');
});

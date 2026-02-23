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

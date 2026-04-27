<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

function addInlineComment($page, string $body = 'Test comment'): void
{
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill($body);
    $page->press('Save');
    $page->assertSee($body);
}

test('delete comment shows undo toast and click undo restores it', function () {
    $page = $this->visit($this->projectUrl());

    addInlineComment($page, 'Undoable comment');

    // Delete the comment
    $page->page()->getByLabel('Delete comment')->click();
    $page->assertDontSee('Undoable comment');

    // Undo toast should appear
    $page->assertSee('Comment deleted');
    $page->assertSee('Undo');

    // Click undo
    $page->page()->getByTestId('undo-button')->click();

    // Comment should be restored
    $page->assertSee('Undoable comment');
    $page->assertDontSee('Comment deleted');
});

test('delete comment then Cmd+Z restores it', function () {
    $page = $this->visit($this->projectUrl());

    addInlineComment($page, 'Keyboard undo comment');

    $page->page()->getByLabel('Delete comment')->click();
    $page->assertDontSee('Keyboard undo comment');
    $page->assertSee('Comment deleted');

    // Press Cmd+Z on body element
    $page->page()->locator('body')->press('Meta+z');

    $page->assertSee('Keyboard undo comment');
});

test('Cmd+Z inside textarea does native undo not comment restore', function () {
    $page = $this->visit($this->projectUrl());

    addInlineComment($page, 'Do not undo this');

    $page->page()->getByLabel('Delete comment')->click();
    $page->assertSee('Comment deleted');

    // Focus the global comment textarea and type something
    $textarea = $page->page()->getByPlaceholder('Overall review comment', false);
    $textarea->fill('typed text');

    // Cmd+Z inside textarea should not trigger comment restore
    $textarea->press('Meta+z');

    // The deleted comment should NOT be restored (undo was consumed by textarea)
    $page->assertDontSee('Do not undo this');
});

test('clear all comments shows undo toast and click undo restores all', function () {
    $page = $this->visit($this->projectUrl());

    addInlineComment($page, 'Bulk undo comment');

    // arm-commit pattern: first click arms, second click commits
    $page->page()->getByLabel('Clear all comments')->click();
    $page->page()->getByLabel('Confirm?')->click();

    // Wait for toast
    $page->assertSee('Cleared 1 comment');
    $page->assertDontSee('Bulk undo comment');

    // Click undo
    $page->page()->getByTestId('undo-button')->click();

    $page->assertSee('Bulk undo comment');
});

test('undo toast shows countdown that decrements', function () {
    $page = $this->visit($this->projectUrl());

    addInlineComment($page, 'Countdown comment');

    $page->page()->getByLabel('Delete comment')->click();
    $page->assertSee('Comment deleted');

    // Verify countdown starts at 10s
    $page->assertSee('10s');

    // Wait 2 seconds, verify countdown has decremented
    $page->page()->waitForFunction(
        "document.querySelector('[data-testid=\"undo-toast\"]')?.textContent?.includes('8s')",
        null,
        ['timeout' => 5000],
    );
});

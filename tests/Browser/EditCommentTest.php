<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('finalized comment shows edit button', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Editable comment');
    $page->press('Save');

    $page->assertSee('Editable comment');
    expect($page->page()->getByTestId('edit-comment')->count())->toBeGreaterThanOrEqual(1);
});

test('clicking edit button opens form with pre-filled text', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Original text');
    $page->press('Save');

    $page->assertSee('Original text');

    $page->page()->getByTestId('edit-comment')->first()->click();

    $page->assertSee('Cancel');
    expect($page->page()->getByPlaceholder('Write a comment', false)->inputValue())->toBe('Original text');
});

test('editing finalized comment and saving updates the comment', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Before edit');
    $page->press('Save');

    $page->assertSee('Before edit');

    $page->page()->getByTestId('edit-comment')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('After edit');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Meta+Enter');

    $page->assertSee('After edit');
    $page->assertDontSee('Before edit');
    // Should remain finalized (no Draft badge)
    expect($page->page()->getByTestId('draft-comment')->count())->toBe(0);
});

test('canceling edit restores original comment', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Do not change');
    $page->press('Save');

    $page->assertSee('Do not change');

    $page->page()->getByTestId('edit-comment')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Changed text');
    $page->press('Cancel');

    $page->assertSee('Do not change');
    $page->assertDontSee('Changed text');
});

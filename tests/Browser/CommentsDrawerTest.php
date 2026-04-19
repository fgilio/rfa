<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('the header shows a comments-drawer trigger button', function () {
    $page = $this->visit($this->projectUrl());

    expect($page->page()->getByLabel('All comments in this repo')->count())->toBeGreaterThan(0);
});

test('adding a comment pops the count on the drawer trigger badge', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('My first note');
    $page->press('Save');
    $page->assertSee('My first note');

    expect($page->page()->getByLabel('All comments in this repo')->innerText())->toContain('1');
});

test('opening the drawer lists the comment body with its file path', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Drawer body text');
    $page->press('Save');
    $page->assertSee('Drawer body text');

    // Click the drawer trigger.
    $page->page()->getByLabel('All comments in this repo')->click();

    $page->assertSee('All comments');
    $page->assertSee('Drawer body text');
    $page->assertSee('hello.php');
});

test('the drawer filter narrows the visible comments', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // Two comments on hello.php.
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('apple note');
    $page->press('Save');
    $page->assertSee('apple note');

    $page->page()->getByTestId('diff-line-number')->nth(2)->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('banana note');
    $page->press('Save');
    $page->assertSee('banana note');

    $page->page()->getByLabel('All comments in this repo')->click();
    $page->page()->getByPlaceholder('Filter comments...')->fill('apple');

    $page->assertSee('apple note');
    $page->page()->locator('[aria-label="All comments in this repo"] + div')->getByText('banana note')->waitFor(['state' => 'hidden']);
});

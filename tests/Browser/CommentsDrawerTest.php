<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('the header shows a comments-drawer trigger button', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('All comments in this repo')->first()->waitFor();
    expect($page->page()->getByLabel('All comments in this repo')->count())->toBeGreaterThan(0);
});

test('adding a comment pops the count on the drawer trigger badge', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('My first note');
    $page->press('Save');
    $page->assertSee('My first note');

    // The drawer hydrates lazily and the badge updates on a separate
    // round-trip, so poll instead of asserting once.
    $page->page()->waitForFunction(
        "document.querySelector('[aria-label=\"All comments in this repo\"]')?.textContent?.includes('1')"
    );
});

test('opening the drawer lists the comment body with its file path', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Drawer body text');
    $page->press('Save');
    $page->assertSee('Drawer body text');

    $page->page()->getByLabel('All comments in this repo')->click();
    $page->page()->getByPlaceholder('Filter comments...')->waitFor();

    $page->assertSee('Drawer body text');
    $page->assertSee('hello.php');
});

test('the drawer filter narrows the visible comments', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('apple note');
    $page->press('Save');
    $page->assertSee('apple note');

    $page->page()->getByTestId('diff-line-number')->nth(2)->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('banana note');
    $page->press('Save');
    $page->assertSee('banana note');

    $page->page()->getByLabel('All comments in this repo')->click();
    $page->page()->getByPlaceholder('Filter comments...')->waitFor();
    $page->page()->getByPlaceholder('Filter comments...')->fill('apple');

    // Wait for Livewire's debounced filter + re-render to hide the non-matching row.
    $page->page()->waitForFunction(<<<'JS'
        (() => {
            const panel = document.querySelector('[aria-label="All comments in this repo"]')
                ?.closest('.relative')
                ?.querySelector('.overflow-y-auto');
            if (!panel) return false;
            const text = panel.textContent ?? '';
            return text.includes('apple note') && !text.includes('banana note');
        })()
    JS);
});

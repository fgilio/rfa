<?php

beforeEach(function () {
    $this->setUpScrollableTestRepo();
});

test('dragging toward viewport bottom auto-scrolls and extends selection', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $scrollableFile = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("scrollable.txt"))');

    // Wait for diff to load
    $scrollableFile->locator('td[data-testid="diff-line-number"]')->first()->waitFor();

    // Record initial scroll position
    $initialScroll = $page->page()->evaluate('window.scrollY');

    // Mousedown on the first new-line number, then pointermove to bottom edge zone
    $page->script("
        const file = document.querySelector('.group:has([data-testid=\"file-header\"])');
        const td = file.querySelector('td[data-testid=\"diff-line-number\"]:nth-child(2)');
        const rect = td.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;

        td.dispatchEvent(new MouseEvent('mousedown', {
            button: 0, buttons: 1, clientX: cx, clientY: cy, bubbles: true
        }));

        // Move to bottom edge zone (within 70px of viewport bottom)
        window.dispatchEvent(new PointerEvent('pointermove', {
            pointerId: 1, buttons: 1, clientX: cx, clientY: window.innerHeight - 20, bubbles: true
        }));
    ");

    // Wait for auto-scroll to advance
    $threshold = $initialScroll + 50;
    $page->page()->waitForFunction(
        "window.scrollY > {$threshold}",
        null,
        ['timeout' => 3000],
    );

    // End drag
    $page->script("
        window.dispatchEvent(new MouseEvent('mouseup', { button: 0, bubbles: true }));
    ");

    // Comment form should appear
    $page->assertSee('Cancel');
});

test('auto-scroll stops when cursor returns to safe zone', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $scrollableFile = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("scrollable.txt"))');
    $scrollableFile->locator('td[data-testid="diff-line-number"]')->first()->waitFor();

    // Start drag and move to bottom edge
    $page->script("
        const file = document.querySelector('.group:has([data-testid=\"file-header\"])');
        const td = file.querySelector('td[data-testid=\"diff-line-number\"]:nth-child(2)');
        const rect = td.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;

        td.dispatchEvent(new MouseEvent('mousedown', {
            button: 0, buttons: 1, clientX: cx, clientY: cy, bubbles: true
        }));

        window.dispatchEvent(new PointerEvent('pointermove', {
            pointerId: 1, buttons: 1, clientX: cx, clientY: window.innerHeight - 20, bubbles: true
        }));
    ");

    // Let scroll start
    $page->page()->waitForFunction('window.scrollY > 50', null, ['timeout' => 3000]);

    // Move cursor back to safe zone (middle of viewport)
    $page->script("
        window.dispatchEvent(new PointerEvent('pointermove', {
            pointerId: 1, buttons: 1, clientX: 200, clientY: window.innerHeight / 2, bubbles: true
        }));
    ");

    // Small pause for rAF loop to stop
    usleep(100_000);

    $scrollAfterStop = $page->page()->evaluate('window.scrollY');

    // Another pause to confirm scroll has stopped
    usleep(200_000);

    $scrollLater = $page->page()->evaluate('window.scrollY');
    expect($scrollLater)->toBe($scrollAfterStop);

    // Cleanup
    $page->script("
        window.dispatchEvent(new MouseEvent('mouseup', { button: 0, bubbles: true }));
    ");
});

test('buttons=0 on pointermove ends stuck drag', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $scrollableFile = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("scrollable.txt"))');
    $scrollableFile->locator('td[data-testid="diff-line-number"]')->first()->waitFor();

    // Start drag
    $page->script("
        const file = document.querySelector('.group:has([data-testid=\"file-header\"])');
        const td = file.querySelector('td[data-testid=\"diff-line-number\"]:nth-child(2)');
        const rect = td.getBoundingClientRect();

        td.dispatchEvent(new MouseEvent('mousedown', {
            button: 0, buttons: 1,
            clientX: rect.left + rect.width / 2,
            clientY: rect.top + rect.height / 2,
            bubbles: true
        }));
    ");

    // Simulate re-entry with buttons=0 (mouse was released outside window)
    $page->script("
        window.dispatchEvent(new PointerEvent('pointermove', {
            pointerId: 1, buttons: 0, clientX: 200, clientY: 200, bubbles: true
        }));
    ");

    // Drag should have ended, form should appear
    $page->assertSee('Cancel');
});

<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

test('dragging sidebar handle changes sidebar width', function () {
    $page = $this->visit($this->projectUrl());

    $initialWidth = $page->page()->evaluate("document.querySelector('aside').offsetWidth");

    // Simulate drag: mousedown on handle, mousemove +100px, mouseup
    $page->script("
        const handle = document.querySelector('aside .cursor-col-resize');
        const rect = handle.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;

        handle.dispatchEvent(new MouseEvent('mousedown', {
            clientX: startX, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mousemove', {
            clientX: startX + 100, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mouseup', {
            clientX: startX + 100, clientY: startY, bubbles: true
        }));
    ");

    // Wait for rAF + Alpine sync
    $page->page()->waitForFunction(
        "Math.abs(document.querySelector('aside').offsetWidth - (initial + 100)) < 5",
        ['initial' => $initialWidth],
    );

    $newWidth = $page->page()->evaluate("document.querySelector('aside').offsetWidth");

    expect($newWidth)->toBeGreaterThan($initialWidth);
    expect(abs($newWidth - $initialWidth - 100))->toBeLessThan(5);
});

test('sidebar width persists in localStorage after drag', function () {
    $page = $this->visit($this->projectUrl());

    $page->script("
        const handle = document.querySelector('aside .cursor-col-resize');
        const rect = handle.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;

        handle.dispatchEvent(new MouseEvent('mousedown', {
            clientX: startX, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mousemove', {
            clientX: startX + 50, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mouseup', {
            clientX: startX + 50, clientY: startY, bubbles: true
        }));
    ");

    $page->page()->waitForFunction("localStorage.getItem('rfa-sidebar-width') !== null");

    $stored = $page->page()->evaluate("parseInt(localStorage.getItem('rfa-sidebar-width'))");
    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");

    expect(abs($stored - $width))->toBeLessThan(5);
});

test('sidebar width clamps at minimum 200px', function () {
    $page = $this->visit($this->projectUrl());

    $page->script("
        const handle = document.querySelector('aside .cursor-col-resize');
        const rect = handle.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;

        handle.dispatchEvent(new MouseEvent('mousedown', {
            clientX: startX, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mousemove', {
            clientX: startX - 500, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mouseup', {
            clientX: startX - 500, clientY: startY, bubbles: true
        }));
    ");

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth === 200");

    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");
    expect($width)->toBe(200);
});

test('sidebar width clamps at maximum 600px', function () {
    $page = $this->visit($this->projectUrl());

    $page->script("
        const handle = document.querySelector('aside .cursor-col-resize');
        const rect = handle.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;

        handle.dispatchEvent(new MouseEvent('mousedown', {
            clientX: startX, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mousemove', {
            clientX: startX + 1000, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mouseup', {
            clientX: startX + 1000, clientY: startY, bubbles: true
        }));
    ");

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth === 600");

    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");
    expect($width)->toBe(600);
});

test('main content gets pointer-events-none during drag', function () {
    $page = $this->visit($this->projectUrl());

    // Start drag but don't release
    $page->script("
        const handle = document.querySelector('aside .cursor-col-resize');
        const rect = handle.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;

        handle.dispatchEvent(new MouseEvent('mousedown', {
            clientX: startX, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mousemove', {
            clientX: startX + 10, clientY: startY, bubbles: true
        }));
    ");

    $page->page()->waitForFunction(
        "document.querySelector('main').classList.contains('pointer-events-none')"
    );

    $duringDrag = $page->page()->evaluate(
        "document.querySelector('main').classList.contains('pointer-events-none')"
    );
    expect($duringDrag)->toBeTrue();

    // Release
    $page->script("
        document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    ");

    $page->page()->waitForFunction(
        "!document.querySelector('main').classList.contains('pointer-events-none')"
    );
});

test('double-clicking resize handle resets sidebar to default width', function () {
    $page = $this->visit($this->projectUrl());

    // First drag sidebar wider
    $page->script("
        const handle = document.querySelector('aside .cursor-col-resize');
        const rect = handle.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;

        handle.dispatchEvent(new MouseEvent('mousedown', {
            clientX: startX, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mousemove', {
            clientX: startX + 100, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mouseup', {
            clientX: startX + 100, clientY: startY, bubbles: true
        }));
    ");

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth > 300");

    // Double-click the handle
    $page->script("
        const handle = document.querySelector('aside .cursor-col-resize');
        handle.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
    ");

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth === 288");

    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");
    expect($width)->toBe(288);

    $stored = $page->page()->evaluate("parseInt(localStorage.getItem('rfa-sidebar-width'))");
    expect($stored)->toBe(288);
});

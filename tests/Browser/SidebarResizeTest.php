<?php

use Pest\Browser\Api\PendingAwaitablePage;

beforeEach(function () {
    $this->setUpTestRepo();
});

dataset('shell pages', [
    'review page' => [''],
    'context page' => ['/context'],
]);

/**
 * Drag the sidebar resize handle by `$deltaX` pixels.
 *
 * Set `$release` to false to leave the drag in-flight (e.g. for asserting
 * mid-drag state); a follow-up `mouseup` script can finish it.
 */
function dragSidebar(PendingAwaitablePage $page, int $deltaX, bool $release = true): void
{
    $script = <<<JS
        const handle = document.querySelector('[data-testid=sidebar-resize-handle]');
        const rect = handle.getBoundingClientRect();
        const startX = rect.left + rect.width / 2;
        const startY = rect.top + rect.height / 2;

        handle.dispatchEvent(new MouseEvent('mousedown', {
            clientX: startX, clientY: startY, bubbles: true
        }));
        document.dispatchEvent(new MouseEvent('mousemove', {
            clientX: startX + {$deltaX}, clientY: startY, bubbles: true
        }));
    JS;

    if ($release) {
        $script .= <<<JS

        document.dispatchEvent(new MouseEvent('mouseup', {
            clientX: startX + {$deltaX}, clientY: startY, bubbles: true
        }));
        JS;
    }

    $page->script($script);
}

test('dragging sidebar handle changes sidebar width', function (string $suffix) {
    $page = $this->visit($this->projectUrl().$suffix);

    $initialWidth = $page->page()->evaluate("document.querySelector('aside').offsetWidth");

    dragSidebar($page, 100);

    $page->page()->waitForFunction(
        "Math.abs(document.querySelector('aside').offsetWidth - (initial + 100)) < 5",
        ['initial' => $initialWidth],
    );

    $newWidth = $page->page()->evaluate("document.querySelector('aside').offsetWidth");

    expect($newWidth)->toBeGreaterThan($initialWidth);
    expect(abs($newWidth - $initialWidth - 100))->toBeLessThan(5);
})->with('shell pages');

test('sidebar width persists in localStorage after drag', function (string $suffix) {
    $page = $this->visit($this->projectUrl().$suffix);

    dragSidebar($page, 50);

    $page->page()->waitForFunction("localStorage.getItem('rfa.sidebarWidth') !== null");

    $stored = $page->page()->evaluate("JSON.parse(localStorage.getItem('rfa.sidebarWidth'))");
    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");

    expect(abs($stored - $width))->toBeLessThan(5);
})->with('shell pages');

test('sidebar width clamps at minimum 200px', function (string $suffix) {
    $page = $this->visit($this->projectUrl().$suffix);

    dragSidebar($page, -500);

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth === 200");

    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");
    expect($width)->toBe(200);
})->with('shell pages');

test('sidebar width clamps at maximum 600px', function (string $suffix) {
    $page = $this->visit($this->projectUrl().$suffix);

    dragSidebar($page, 1000);

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth === 600");

    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");
    expect($width)->toBe(600);
})->with('shell pages');

test('main content gets pointer-events-none during drag', function (string $suffix) {
    $page = $this->visit($this->projectUrl().$suffix);

    dragSidebar($page, 10, release: false);

    $page->page()->waitForFunction(
        "document.querySelector('main').classList.contains('pointer-events-none')"
    );

    $duringDrag = $page->page()->evaluate(
        "document.querySelector('main').classList.contains('pointer-events-none')"
    );
    expect($duringDrag)->toBeTrue();

    $page->script("
        document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    ");

    $page->page()->waitForFunction(
        "!document.querySelector('main').classList.contains('pointer-events-none')"
    );
})->with('shell pages');

test('double-clicking resize handle resets sidebar to default width', function (string $suffix) {
    $page = $this->visit($this->projectUrl().$suffix);

    dragSidebar($page, 100);

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth > 300");

    $page->script("
        const handle = document.querySelector('[data-testid=sidebar-resize-handle]');
        handle.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
    ");

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth === 288");

    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");
    expect($width)->toBe(288);

    $stored = $page->page()->evaluate("JSON.parse(localStorage.getItem('rfa.sidebarWidth'))");
    expect($stored)->toBe(288);
})->with('shell pages');

test('window blur during drag finishes the resize cleanly', function (string $suffix) {
    $page = $this->visit($this->projectUrl().$suffix);

    dragSidebar($page, 25, release: false);

    $page->page()->waitForFunction(
        "document.querySelector('main').classList.contains('pointer-events-none')"
    );

    // Alt-tab simulator: window losing focus must trigger the same teardown
    // that mouseup does, so resizing flips off and pointer-events re-enable.
    $page->script("window.dispatchEvent(new Event('blur'));");

    $page->page()->waitForFunction(
        "!document.querySelector('main').classList.contains('pointer-events-none')"
    );

    $page->page()->waitForFunction("localStorage.getItem('rfa.sidebarWidth') !== null");
    $stored = $page->page()->evaluate("JSON.parse(localStorage.getItem('rfa.sidebarWidth'))");
    $width = $page->page()->evaluate("document.querySelector('aside').offsetWidth");

    expect(abs($stored - $width))->toBeLessThan(5);
})->with('shell pages');

test('sidebar width set on review page persists on context page', function () {
    $page = $this->visit($this->projectUrl());

    dragSidebar($page, 80);

    $page->page()->waitForFunction("document.querySelector('aside').offsetWidth > 350");
    $reviewWidth = $page->page()->evaluate("document.querySelector('aside').offsetWidth");

    // SPA-navigate to the context page in the same browser context so the
    // shared Alpine.$persist store survives the page swap.
    $page->script("Livewire.navigate('".$this->projectUrl()."/context')");

    $page->page()->waitForFunction("window.location.pathname.endsWith('/context')");
    $page->page()->waitForFunction(
        "Math.abs(document.querySelector('aside').offsetWidth - target) < 5",
        ['target' => $reviewWidth],
    );

    $contextWidth = $page->page()->evaluate("document.querySelector('aside').offsetWidth");
    expect(abs($contextWidth - $reviewWidth))->toBeLessThan(5);
});

test('sidebar stays above a growing fixed feedback bar', function (string $suffix) {
    $page = $this->visitAndLoad($this->projectUrl().$suffix);

    $page->page()->evaluate(<<<'JS'
        (() => {
            const overflowFixture = document.createElement('div');
            overflowFixture.style.height = '2000px';
            document.querySelector('aside').append(overflowFixture);
        })()
    JS);

    $page->page()->waitForFunction(
        "document.querySelector('aside').scrollHeight > document.querySelector('aside').clientHeight"
    );

    $initialFeedbackBarHeight = $page->page()->evaluate(
        "document.querySelector('[data-testid=feedback-submit-bar]').offsetHeight"
    );

    $page->page()->evaluate(<<<'JS'
        (() => {
            const textarea = document.querySelector('[data-testid=feedback-submit-bar] textarea');

            textarea.value = Array.from({ length: 12 }, (_, index) => `Feedback line ${index + 1}`).join('\n');
            textarea.dispatchEvent(new InputEvent('input', { bubbles: true }));
        })()
    JS);

    $page->page()->waitForFunction(
        "document.querySelector('[data-testid=feedback-submit-bar]').offsetHeight > initialHeight",
        ['initialHeight' => $initialFeedbackBarHeight],
    );

    $page->page()->waitForFunction(
        "document.querySelector('aside').getBoundingClientRect().bottom <= document.querySelector('[data-testid=feedback-submit-bar]').getBoundingClientRect().top"
    );

    $positions = $page->page()->evaluate(<<<'JS'
        (() => {
            const sidebar = document.querySelector('aside');
            const feedbackBar = document.querySelector('[data-testid="feedback-submit-bar"]');

            return {
                sidebarBottom: sidebar.getBoundingClientRect().bottom,
                feedbackTop: feedbackBar.getBoundingClientRect().top,
            };
        })()
    JS);

    expect($positions['sidebarBottom'])->toBeLessThanOrEqual($positions['feedbackTop']);
})->with('shell pages');

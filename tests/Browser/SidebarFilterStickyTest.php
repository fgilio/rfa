<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

dataset('pages with a sidebar filter', [
    'review page' => [''],
    'context page' => ['/context'],
]);

test('sidebar filter stays at the top while its content scrolls', function (string $suffix) {
    $page = $this->visitAndLoad($this->projectUrl().$suffix);

    $initialOffset = $page->page()->evaluate(<<<'JS'
        (() => {
            const sidebar = document.querySelector('aside');
            const filterBar = document.querySelector('[data-testid="sidebar-filter-bar"]');

            return filterBar.getBoundingClientRect().top - sidebar.getBoundingClientRect().top;
        })()
    JS);

    $page->page()->evaluate(<<<'JS'
        (() => {
            const filterBar = document.querySelector('[data-testid="sidebar-filter-bar"]');
            const overflowFixture = document.createElement('div');

            overflowFixture.style.height = '2000px';
            filterBar.parentElement.append(overflowFixture);
        })()
    JS);

    $page->page()->waitForFunction(
        "document.querySelector('aside').scrollHeight > document.querySelector('aside').clientHeight"
    );

    $page->page()->evaluate("document.querySelector('aside').scrollTop = 1000");
    $page->page()->waitForFunction("document.querySelector('aside').scrollTop > 0");

    $positions = $page->page()->evaluate(<<<'JS'
        (() => {
            const sidebar = document.querySelector('aside');
            const filterBar = document.querySelector('[data-testid="sidebar-filter-bar"]');

            return {
                sidebarTop: sidebar.getBoundingClientRect().top,
                filterTop: filterBar.getBoundingClientRect().top,
            };
        })()
    JS);

    expect(abs($initialOffset))->toBeLessThanOrEqual(1)
        ->and(abs($positions['filterTop'] - $positions['sidebarTop']))->toBeLessThanOrEqual(1);
})->with('pages with a sidebar filter');

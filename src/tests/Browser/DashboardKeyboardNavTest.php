<?php

beforeEach(function () {
    $this->setUpRegisteredProjects(['alpha-project', 'beta-project', 'gamma-project']);
});

// -- Arrow navigation --

test('arrow down selects first project', function () {
    $page = $this->visit('/');
    $this->pressGlobalKey($page, 'ArrowDown');

    $id = $page->script("document.querySelector('[data-testid=\"project-card\"][data-selected]')?.dataset.projectId");
    expect($id)->not->toBeNull();
});

test('arrow keys cycle through projects', function () {
    $page = $this->visit('/');

    // Down twice, then up once -> should be on first card (index 0)
    $this->pressGlobalKey($page, 'ArrowDown');
    $this->pressGlobalKey($page, 'ArrowDown');
    $this->pressGlobalKey($page, 'ArrowUp');

    $selectedId = $page->script("document.querySelector('[data-testid=\"project-card\"][data-selected]')?.dataset.projectId");
    $firstId = $page->script("document.querySelectorAll('[data-testid=\"project-card\"]')[0]?.dataset.projectId");
    expect($selectedId)->toBe($firstId);
});

test('arrow bounds are clamped', function () {
    $page = $this->visit('/');

    // Press ArrowDown 10 times (only 3 projects)
    for ($i = 0; $i < 10; $i++) {
        $this->pressGlobalKey($page, 'ArrowDown');
    }

    $selectedId = $page->script("document.querySelector('[data-testid=\"project-card\"][data-selected]')?.dataset.projectId");
    $lastId = $page->script("[...document.querySelectorAll('[data-testid=\"project-card\"]')].at(-1)?.dataset.projectId");
    expect($selectedId)->toBe($lastId);
});

test('enter opens selected project', function () {
    $page = $this->visit('/');
    $this->pressGlobalKey($page, 'ArrowDown');
    $this->pressGlobalKey($page, 'Enter');

    $page->page()->waitForURL('**/p/**');
    $url = $page->script('window.location.pathname');
    expect($url)->toContain('/p/');
});

// -- Escape --

test('escape clears selection', function () {
    $page = $this->visit('/');
    $this->pressGlobalKey($page, 'ArrowDown');

    // Verify something is selected
    $before = $page->script("document.querySelector('[data-testid=\"project-card\"][data-selected]')?.dataset.projectId");
    expect($before)->not->toBeNull();

    $this->pressGlobalKey($page, 'Escape');

    $after = $page->script("document.querySelector('[data-testid=\"project-card\"][data-selected]')?.dataset.projectId");
    expect($after)->toBeNull();
});

// -- Search + nav --

test('search and arrow nav on filtered results', function () {
    $page = $this->visit('/');

    // Type a filter that matches only one project
    $page->page()->getByPlaceholder('Filter projects...')->fill('beta');

    // Wait for 150ms debounce + Alpine reactivity to hide non-matching cards
    $page->page()->waitForFunction("document.querySelectorAll('[data-project-card]:not([style*=\"display: none\"])').length === 1");

    $this->pressGlobalKey($page, 'ArrowDown');

    // Wait for selection to land on the visible card (atomic check avoids race between reads)
    $page->page()->waitForFunction("
        document.querySelector('[data-project-card]:not([style*=\"display: none\"])[data-selected]') !== null
    ");

    $selectedId = $page->script("document.querySelector('[data-testid=\"project-card\"][data-selected]')?.dataset.projectId");
    expect($selectedId)->not->toBeNull();
});

// -- Slash to focus --

test('slash focuses search input', function () {
    $page = $this->visit('/');

    // Blur the search input first
    $page->script('document.activeElement?.blur()');

    $this->pressGlobalKey($page, '/');

    $isFocused = $page->script("document.activeElement?.placeholder === 'Filter projects...' || document.activeElement?.closest('[x-ref=\"searchInput\"]') !== null");
    expect($isFocused)->toBe(true);
});

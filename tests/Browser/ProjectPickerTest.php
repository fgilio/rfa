<?php

beforeEach(function () {
    $this->setUpRegisteredProjects(['alpha-project', 'beta-project', 'gamma-project']);
});

// -- Opening --

test('clicking the header project name opens the picker', function () {
    $slug = $this->testProjectSlugs[0];

    $page = $this->visit('/p/'.$slug);

    $page->page()->getByRole('button', ['name' => 'Switch project'])->click();

    $page->page()->waitForFunction("document.querySelectorAll('[data-project-picker-row]').length > 0");

    $rowCount = $page->script("document.querySelectorAll('[data-project-picker-row]').length");
    expect($rowCount)->toBe(3);
});

test('cmd+k opens the picker', function () {
    $slug = $this->testProjectSlugs[0];

    $page = $this->visit('/p/'.$slug);

    $this->pressGlobalKey($page, 'k', ['metaKey' => true]);

    $page->page()->waitForFunction("document.querySelectorAll('[data-project-picker-row]').length > 0");

    $rowCount = $page->script("document.querySelectorAll('[data-project-picker-row]').length");
    expect($rowCount)->toBe(3);
});

// -- Search + navigation --

test('search filters picker rows', function () {
    $slug = $this->testProjectSlugs[0];

    $page = $this->visit('/p/'.$slug);

    $this->pressGlobalKey($page, 'k', ['metaKey' => true]);
    $page->page()->waitForFunction("document.querySelectorAll('[data-project-picker-row]').length === 3");

    $page->page()->getByPlaceholder('Switch to project...')->fill('beta');
    $page->page()->waitForFunction("document.querySelectorAll('[data-project-picker-row]').length === 1");

    $visibleSlug = $page->script("document.querySelector('[data-project-picker-row]')?.dataset.slug");
    expect($visibleSlug)->toContain('beta');
});

test('escape closes the picker', function () {
    $slug = $this->testProjectSlugs[0];

    $page = $this->visit('/p/'.$slug);

    $this->pressGlobalKey($page, 'k', ['metaKey' => true]);
    $page->page()->waitForFunction("document.querySelectorAll('[data-project-picker-row]').length > 0");

    $this->pressGlobalKey($page, 'Escape');

    // waitForFunction blocks until the dialog is hidden (offsetParent === null means display: none).
    $page->page()->waitForFunction("document.querySelector('[role=\"dialog\"][aria-label=\"Switch project\"]')?.offsetParent === null");
});

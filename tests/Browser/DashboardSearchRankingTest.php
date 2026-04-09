<?php

use App\Models\Project;

beforeEach(function () {
    $this->setUpRegisteredProjects(['proj-a', 'proj-b']);

    // Rename to clean names so search ranking is testable independent of temp dir paths
    $projects = Project::orderBy('id')->get();
    $projects[0]->update(['name' => 'rfa']);
    $projects[1]->update(['name' => 'farfalla']);
});

// -- Search ranking --

test('search ranks exact name match above substring match', function () {
    $page = $this->visit('/');

    $page->page()->getByPlaceholder('Filter projects...')->fill('rfa');

    // Wait for debounce + Alpine reactivity — both should match ("rfa" exact, "farfalla" contains "rfa")
    $page->page()->waitForFunction("document.querySelectorAll('[data-project-card]:not([style*=\"display: none\"])').length === 2");

    $this->pressGlobalKey($page, 'ArrowDown');

    $page->page()->waitForFunction("
        document.querySelector('[data-project-card]:not([style*=\"display: none\"])[data-selected]') !== null
    ");

    // First selected card should be the exact match "rfa", not "farfalla"
    $selectedText = $page->script("document.querySelector('[data-project-card][data-selected]')?.textContent");
    expect($selectedText)->toContain('rfa')
        ->and($selectedText)->not->toContain('farfalla');
});

test('search ranks start-of-word above mid-word match', function () {
    // Rename to test word-boundary ranking
    $projects = Project::orderBy('id')->get();
    $projects[0]->update(['name' => 'my-api-tool']);
    $projects[1]->update(['name' => 'rapid']);

    $page = $this->visit('/');

    $page->page()->getByPlaceholder('Filter projects...')->fill('api');

    // Both match: "my-api-tool" has word-start match, "rapid" has mid-word substring
    $page->page()->waitForFunction("document.querySelectorAll('[data-project-card]:not([style*=\"display: none\"])').length === 2");

    $this->pressGlobalKey($page, 'ArrowDown');

    $page->page()->waitForFunction("
        document.querySelector('[data-project-card]:not([style*=\"display: none\"])[data-selected]') !== null
    ");

    // Word-start match "my-api-tool" should rank above mid-word "rapid"
    $selectedText = $page->script("document.querySelector('[data-project-card][data-selected]')?.textContent");
    expect($selectedText)->toContain('my-api-tool');
});

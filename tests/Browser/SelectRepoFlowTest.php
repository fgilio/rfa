<?php

beforeEach(function () {
    $this->setUpRegisteredProjects(['alpha', 'beta', 'gamma']);
});

test('select-repo page lists all registered repos with the pick heading', function () {
    $alphaName = basename($this->testRepoPaths[0]);
    $betaName = basename($this->testRepoPaths[1]);
    $gammaName = basename($this->testRepoPaths[2]);

    $this->visitAndLoad(route('select-repo'))
        ->assertSee('Pick a repo')
        ->assertSee($alphaName)
        ->assertSee($betaName)
        ->assertSee($gammaName);
});

test('deleting a repo from the select-repo list updates the list in place', function () {
    $alphaName = basename($this->testRepoPaths[0]);
    $betaName = basename($this->testRepoPaths[1]);

    $page = $this->visitAndLoad(route('select-repo'));

    // wire:confirm uses the browser's native confirm dialog.
    $page->script('window.confirm = function() { return true; }');

    $page->page()->getByLabel("Remove {$alphaName}")->click();

    $page->assertDontSee($alphaName);
    $page->assertSee($betaName);
    $page->assertSee('Pick a repo');
});

test('deleting the final repo reveals the empty state', function () {
    $alphaName = basename($this->testRepoPaths[0]);
    $betaName = basename($this->testRepoPaths[1]);
    $gammaName = basename($this->testRepoPaths[2]);

    $page = $this->visitAndLoad(route('select-repo'));
    $page->script('window.confirm = function() { return true; }');

    $page->page()->getByLabel("Remove {$alphaName}")->click();
    $page->page()->getByLabel("Remove {$betaName}")->click();
    $page->page()->getByLabel("Remove {$gammaName}")->click();

    $page->assertSee('No repos yet');
    $page->assertDontSee('Pick a repo');
});

test('clicking a repo navigates into its review page', function () {
    $alphaName = basename($this->testRepoPaths[0]);

    $page = $this->visitAndLoad(route('select-repo'));
    $page->page()->getByText($alphaName)->first()->click();

    $page->assertSee($alphaName)
        ->assertDontSee('Pick a repo');
});

test('search filters the repo list to matches only', function () {
    $alphaName = basename($this->testRepoPaths[0]);
    $betaName = basename($this->testRepoPaths[1]);

    $page = $this->visitAndLoad(route('select-repo'));

    $searchInput = $page->page()->getByPlaceholder('Search repos...');
    $searchInput->fill($alphaName);

    $page->assertSee($alphaName)
        ->assertDontSee($betaName);
});

test('deleting the current repo from the picker lands on select-repo with remaining repos', function () {
    $currentSlug = $this->testProjectSlugs[0];
    $currentName = basename($this->testRepoPaths[0]);
    $betaName = basename($this->testRepoPaths[1]);

    $page = $this->visitAndLoad(route('review-page', ['slug' => $currentSlug]));

    $page->script('window.confirm = function() { return true; }');

    $page->page()->getByLabel('Switch repo (⌘K)')->click();

    // Hover the row to reveal the trash button (picker mode hides it until hover).
    $page->page()->locator('[data-project-picker-row][data-slug="'.$currentSlug.'"]')->hover();
    $page->page()->getByLabel("Remove {$currentName}")->click();

    $page->assertSee('Pick a repo')
        ->assertSee($betaName)
        ->assertDontSee($currentName);
});

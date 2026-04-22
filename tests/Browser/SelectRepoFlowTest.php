<?php

beforeEach(function () {
    $this->setUpRegisteredProjects(['alpha', 'beta', 'gamma']);
});

test('select-repo page lists all registered repos with the pick heading', function () {
    $this->visit(route('select-repo'))
        ->assertSee('Pick a repo')
        ->assertSee($this->projectName(0))
        ->assertSee($this->projectName(1))
        ->assertSee($this->projectName(2));
});

test('deleting a repo from the select-repo list updates the list in place', function () {
    $alphaSlug = $this->testProjectSlugs[0];
    $alphaName = $this->projectName(0);
    $betaName = $this->projectName(1);

    $page = $this->visit(route('select-repo'));

    // wire:confirm invokes the browser's native confirm dialog; force-accept.
    $page->script('window.confirm = function() { return true; }');

    $page->page()->getByTestId("remove-repo-{$alphaSlug}")->click();

    $page->page()->getByText($alphaName)->first()->waitFor(['state' => 'hidden']);

    $page->assertSee($betaName);
    $page->assertSee('Pick a repo');
});

test('deleting the final repo reveals the empty state', function () {
    $page = $this->visit(route('select-repo'));
    $page->script('window.confirm = function() { return true; }');

    foreach ($this->testProjectSlugs as $i => $slug) {
        $page->page()->getByTestId("remove-repo-{$slug}")->click();
        $page->page()->getByText($this->projectName($i))->first()->waitFor(['state' => 'hidden']);
    }

    $page->assertSee('No repos yet');
});

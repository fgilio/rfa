<?php

beforeEach(function () {
    $this->setUpRegisteredProjects(['alpha', 'beta', 'gamma']);
});

test('select-repo page lists all registered repos with the pick heading', function () {
    $alphaName = basename($this->testRepoPaths[0]);
    $betaName = basename($this->testRepoPaths[1]);
    $gammaName = basename($this->testRepoPaths[2]);

    $this->visit(route('select-repo'))
        ->assertSee('Pick a repo')
        ->assertSee($alphaName)
        ->assertSee($betaName)
        ->assertSee($gammaName);
});

test('deleting a repo from the select-repo list updates the list in place', function () {
    $alphaName = basename($this->testRepoPaths[0]);
    $betaName = basename($this->testRepoPaths[1]);

    $page = $this->visit(route('select-repo'));

    // wire:confirm invokes the browser's native confirm dialog; force-accept.
    $page->script('window.confirm = function() { return true; }');

    $page->page()->getByLabel("Remove {$alphaName}")->waitFor();
    $page->page()->getByLabel("Remove {$alphaName}")->click();

    $page->page()->getByText($alphaName)->first()->waitFor(['state' => 'hidden']);

    $page->assertSee($betaName);
    $page->assertSee('Pick a repo');
});

test('deleting the final repo reveals the empty state', function () {
    $names = array_map(fn (string $p) => basename($p), $this->testRepoPaths);

    $page = $this->visit(route('select-repo'));
    $page->script('window.confirm = function() { return true; }');

    foreach ($names as $name) {
        $page->page()->getByLabel("Remove {$name}")->waitFor();
        $page->page()->getByLabel("Remove {$name}")->click();
        $page->page()->getByText($name)->first()->waitFor(['state' => 'hidden']);
    }

    $page->assertSee('No repos yet');
});

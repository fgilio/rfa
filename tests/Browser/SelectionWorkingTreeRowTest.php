<?php

use App\Models\Project;

test('branch-explorer panel shows a Working tree row at the top of the commit list', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByPlaceholder('Filter branch')->waitFor();

    $row = $page->page()->getByTestId('working-tree-row');
    $row->waitFor();
    expect($row->innerText())->toContain('Working tree');

    $firstRow = $page->page()->locator('[data-testid="working-tree-row"], [data-testid="commit-row"]')->first();
    expect($firstRow->getAttribute('data-testid'))->toBe('working-tree-row');

    $page->assertNoJavaScriptErrors();
});

test('branch-explorer commit rows keep their Alpine scope when remote menus are enabled', function () {
    $this->setUpCommitHistoryRepo();

    Project::where('slug', $this->testProjectSlug)
        ->update(['remote_url' => 'git@github.com:acme/example.git']);

    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByTestId('commit-row')->first()->waitFor();

    $page->assertNoJavaScriptErrors();
});

test('Working tree row shows active state when viewing the working tree', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByTestId('working-tree-row')->waitFor();

    expect($page->page()->getByTestId('working-tree-row')->getAttribute('aria-current'))->toBe('true');
});

test('Working tree row has no active state when viewing a commit', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);
    $page->page()->getByTestId('commit-context-bar')->waitFor();

    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByTestId('working-tree-row')->waitFor();

    expect($page->page()->getByTestId('working-tree-row')->getAttribute('aria-current'))->toBeNull();
});

test('clicking the Working tree row navigates back to the working tree', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);
    $page->page()->getByTestId('commit-context-bar')->waitFor();

    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByTestId('working-tree-row')->waitFor();
    $page->page()->getByTestId('working-tree-row')->click();

    $page->assertDontSee('Add type hints and utils');
    expect($page->page()->getByTestId('commit-context-bar')->count())->toBe(0);
});

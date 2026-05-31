<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepo();
    $this->runTestRepoCommand($this->testRepoPath, "git branch feature-x {$this->commitHashes[0]}");
});

test('switching branches clears an in-flight commit selection', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    $row = $page->page()->locator('[data-testid="commit-row"]')->filter(['hasText' => 'Add greet function']);
    $row->hover();
    $row->getByTestId('commit-select-toggle')->click();
    $page->page()->getByText('1 selected')->waitFor();

    // Switch to the other local branch. loadSelectedBranch flips
    // selectedBranch, awaits loadSnapshot, and - because the name changed -
    // clears selection.
    $page->page()->locator('[x-ref="branchList"]')->getByText('feature-x')->click();

    // Wait on the Clear-selection affordance itself: getByText('selected') is a
    // substring match that also catches toasts, tripping Playwright strict mode.
    $page->page()->getByLabel('Clear selection')->waitFor(['state' => 'hidden']);
    $page->assertDontSee('1 selected');
});

<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepoWithWdChange();
});

test('the original line snippet renders when a stored comment becomes unplaced', function () {
    $wdPage = $this->visitAndLoad($this->projectUrl());

    $wdNewLine = $wdPage->page()
        ->locator('tr:has(td:has-text("// Updated with WD change")) td[data-testid="diff-line-number"]:nth-child(2)')
        ->first();
    $wdNewLine->waitFor();
    $wdNewLine->click();
    $wdPage->page()->getByPlaceholder('Write a comment', false)->fill('Snippet pinned here');
    $wdPage->press('Save');
    $wdPage->page()->getByText('Snippet pinned here')->first()->waitFor();

    $commitPage = $this->visitAndLoad($this->projectUrl().'/c/'.$this->commitHashes[1]);
    $commitPage->page()->getByTestId('commit-context-bar')->waitFor();

    $commitPage->page()->getByText('Snippet pinned here')->first()->waitFor();
    $commitPage->page()->getByText('Original snippet')->first()->waitFor();
    $commitPage->page()->getByText('// Updated with WD change')->first()->waitFor();
});

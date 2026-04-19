<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepoWithWdChange();
});

test('the original line snippet renders when a stored comment becomes unplaced', function () {
    $wdPage = $this->visitAndLoad($this->projectUrl());

    $wdNewLine = $wdPage->page()
        ->locator('tr:has(td:has-text("// Updated with WD change")) td[data-testid="diff-line-number"]:nth-child(2)')
        ->first();
    $wdNewLine->click();
    $wdPage->page()->getByPlaceholder('Write a comment', false)->fill('Snippet pinned here');
    $wdPage->press('Save');
    $wdPage->assertSee('Snippet pinned here');

    $commitPage = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);
    $commitPage->page()->getByTestId('commit-context-bar')->waitFor();

    $commitPage->assertSee('Snippet pinned here');
    $commitPage->assertSee('Original snippet');
    $commitPage->assertSee('// Updated with WD change');
});

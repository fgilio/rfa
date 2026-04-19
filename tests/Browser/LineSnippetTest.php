<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepoWithWdChange();
});

test('the original line snippet renders when a stored comment becomes unplaced', function () {
    // Author a comment on the working-tree diff targeting the unique WD-added line.
    $wdPage = $this->visitAndLoad($this->projectUrl());

    // Click on the `// Updated with WD change` line (new-side). This line exists only in WD,
    // so when we view any commit the anchor will drop to 'unplaced' and the snippet shows.
    $wdNewLine = $wdPage->page()->locator('tr:has(td:has-text("// Updated with WD change")) td[data-testid="diff-line-number"]:nth-child(2)')->first();
    $wdNewLine->click();
    $wdPage->page()->getByPlaceholder('Write a comment', false)->fill('Snippet pinned here');
    $wdPage->press('Save');
    $wdPage->assertSee('Snippet pinned here');

    // Navigate to a commit where the snippet is NOT in the diff (hello.php without the WD line).
    $commitPage = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);
    $commitPage->page()->getByTestId('commit-context-bar')->waitFor();

    // The body is still rendered (unplaced section), alongside the original snippet label.
    $commitPage->assertSee('Snippet pinned here');
    $commitPage->assertSee('Original snippet');
    $commitPage->assertSee('// Updated with WD change');
});

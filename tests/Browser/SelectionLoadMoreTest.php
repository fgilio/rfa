<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepo();

    // Branch-explorer paginates at 50 commits per page. Pad with 55 bulk
    // commits so the first page fills and "Load more" appears.
    // Zero-pad the counter so "Bulk commit 001" doesn't substring-match
    // "Bulk commit 010" / "011" / "012" when Playwright filters by text.
    // `printf -v` is bash-only; CI uses /bin/sh (dash), so use POSIX
    // command substitution instead.
    $this->runTestRepoCommand($this->testRepoPath, <<<'SH'
        for i in $(seq 1 55); do
            n=$(printf "%03d" $i)
            echo "line $n" >> bulk.txt
            git add -A
            git commit -m "Bulk commit $n" -q
        done
    SH);
});

test('shift-clicking across Load more selects the full range on both pages', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Bulk commit 055')->waitFor();

    // Select the newest bulk commit on page 1.
    $top = $page->page()->locator('[data-testid="commit-row"]')->filter(['hasText' => 'Bulk commit 055']);
    $top->hover();
    $top->getByTestId('commit-select-toggle')->click();
    $page->page()->getByText('1 selected')->waitFor();

    // Expand to page 2 and wait until the oldest bulk commit is visible.
    $page->page()->getByText('Load more commits...')->click();
    $page->page()->getByText('Bulk commit 001')->waitFor();

    // Shift-click the oldest bulk commit — index in $wire.commits is on
    // page 2, so this exercises the across-page range path.
    $bottom = $page->page()->locator('[data-testid="commit-row"]')->filter(['hasText' => 'Bulk commit 001']);
    $bottom->hover();
    $bottom->getByTestId('commit-select-toggle')->click(['modifiers' => ['Shift']]);

    $button = $page->page()->getByRole('button', ['name' => 'Apply working tree + 55 commits']);
    $button->waitFor();

    expect($button->innerText())->toContain('WT+55');
});

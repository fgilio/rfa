<?php

test('selection badge reads "Working tree" when viewing the working tree', function () {
    $this->setUpTestRepo();

    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('Open selection drawer')->waitFor();
    expect($page->page()->getByLabel('Open selection drawer')->innerText())->toContain('Working tree');
});

test('selection badge reads the short SHA in single-commit mode', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->page()->getByLabel('Open selection drawer')->waitFor();
    expect($page->page()->getByLabel('Open selection drawer')->innerText())
        ->toContain($this->commitShortHashes[1]);
});

test('selection badge reads from..to when the URL carries a range', function () {
    $this->setUpCommitHistoryRepo();

    $from = $this->commitHashes[0];
    $to = $this->commitHashes[2];
    $page = $this->visit($this->projectUrl()."/r/{$from}..{$to}");

    $page->page()->getByLabel('Open selection drawer')->waitFor();
    $text = $page->page()->getByLabel('Open selection drawer')->innerText();
    expect($text)->toContain($this->commitShortHashes[0]);
    expect($text)->toContain($this->commitShortHashes[2]);
});

test('clicking the selection badge opens the branch-explorer panel', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();

    $page->page()->getByPlaceholder('Filter branch')->waitFor();
    $page->assertSee('Add greet function');
});

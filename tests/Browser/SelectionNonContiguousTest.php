<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepo();
});

test('Apply rejects a non-contiguous selection and stays on the current page', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    // Pick the newest + oldest commit, skipping the middle. Drive Alpine
    // directly so the test doesn't depend on hover/click timing.
    $selected = json_encode([$this->commitHashes[2], $this->commitHashes[0]]);
    $page->script(<<<JS
        (() => {
            const root = document.querySelector("[x-data*=branchExplorer]");
            const data = Alpine.\$data(root);
            data.selectedHashes = {$selected};
            data.applySelection();
        })()
    JS);

    $page->page()->getByText('Selection is not contiguous')->waitFor();

    $pathname = $page->script('window.location.pathname');

    expect($pathname)
        ->not->toContain('/c/')
        ->not->toContain('/r/');
});

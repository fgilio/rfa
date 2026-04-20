<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepo();
});

test('Apply rejects a non-contiguous selection and stays on the current page', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    // Pick the newest + oldest commit, skipping the middle. Drive Alpine
    // directly so the test doesn't depend on hover/click timing, and trap
    // the `window.alert` that would otherwise block the Playwright driver.
    $selected = json_encode([$this->commitHashes[2], $this->commitHashes[0]]);
    $result = $page->script(<<<JS
        (() => {
            const alerts = [];
            const origAlert = window.alert;
            window.alert = (m) => alerts.push(m);
            const root = document.querySelector("[x-data*=branchExplorer]");
            const data = Alpine.\$data(root);
            data.selectedHashes = {$selected};
            data.applySelection();
            window.alert = origAlert;
            return { alerts, pathname: window.location.pathname };
        })()
    JS);

    expect($result['alerts'])->toHaveCount(1);
    expect($result['alerts'][0])->toContain('not contiguous');
    expect($result['pathname'])
        ->not->toContain('/c/')
        ->not->toContain('/r/');
});

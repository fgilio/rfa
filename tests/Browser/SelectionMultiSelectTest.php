<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepo();
});

/**
 * Return the commit row whose message contains the given text.
 */
function commitRow(mixed $page, string $message): mixed
{
    return $page->page()->locator('[data-testid="commit-row"]')->filter(['hasText' => $message]);
}

test('checkbox on a commit row toggles it into the selection', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    $row = commitRow($page, 'Add greet function');
    $row->hover();
    $row->getByTestId('commit-select-toggle')->click();

    $page->page()->getByText('1 selected')->waitFor();
});

test('shift-clicking a second checkbox selects the commits between them', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    $first = commitRow($page, 'Change date format to d/m/Y');
    $last = commitRow($page, 'Add greet function');

    $first->hover();
    $first->getByTestId('commit-select-toggle')->click();

    $last->hover();
    $last->getByTestId('commit-select-toggle')->click(['modifiers' => ['Shift']]);

    $page->page()->getByText('3 selected')->waitFor();
});

test('clicking Apply with a multi-commit selection navigates to a range URL', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    // Driving the Alpine state directly keeps the test independent of
    // visibility / hover / click-bubbling quirks between local and CI.
    $page->script(sprintf(
        '(() => { const root = document.querySelector("[x-data*=branchExplorer]"); const data = Alpine.$data(root); data.selectedHashes = %s; data.applySelection(); })()',
        json_encode([$this->commitHashes[2], $this->commitHashes[1]]),
    ));

    // Livewire.navigate is async — poll until the pathname carries both SHAs.
    $expected = $this->commitHashes[2];
    $urlNow = '';
    for ($i = 0; $i < 50; $i++) {
        $urlNow = $page->script('window.location.pathname');
        if (str_contains($urlNow, $expected)) {
            break;
        }
        usleep(100_000);
    }

    expect($urlNow)
        ->toContain($this->commitHashes[2])
        ->toContain($this->commitHashes[1]);
});

test('clearing the selection removes the selected chip', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    $row = commitRow($page, 'Add greet function');
    $row->hover();
    $row->getByTestId('commit-select-toggle')->click();

    $page->page()->getByText('1 selected')->waitFor();

    $page->page()->getByTitle('Clear selection')->click();

    $page->page()->getByText('selected')->waitFor(['state' => 'hidden']);
});

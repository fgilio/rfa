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
    // openPanel() auto-rehydrates workingTreeSelected from the active view
    // (project URL = working tree), so untick it before asserting on the
    // pure multi-commit range path.
    $page->script(sprintf(
        '(() => { const root = document.querySelector("[x-data*=branchExplorer]"); const data = Alpine.$data(root); data.workingTreeSelected = false; data.selectedHashes = %s; data.applySelection(); })()',
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

test('shift-clicking a commit after WT is selected forms a WT + commits[0..N] range', function () {
    // Opening on the working-tree URL auto-rehydrates WT as selected, so the
    // subsequent shift+click must extend WT + commits[0..N] without the user
    // re-ticking WT first.
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByText('Add greet function')->waitFor();

    // Sanity: the selection badge shows "WT" on its own when only the working
    // tree is ticked (see `selectionBadge` getter in branch-explorer.js).
    $page->page()->getByText('WT', true)->waitFor();

    $target = commitRow($page, 'Add greet function'); // bottom commit in the fixture
    $target->hover();
    $target->getByTestId('commit-select-toggle')->click(['modifiers' => ['Shift']]);

    // 3-commit fixture; shift-click on the oldest pulls all three + WT.
    $page->page()->getByText('WT+3')->waitFor();
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

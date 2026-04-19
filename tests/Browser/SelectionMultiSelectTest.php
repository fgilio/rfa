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
    $page->page()->locator('text=Add greet function')->waitFor();

    $row = commitRow($page, 'Add greet function');
    $row->hover();
    $row->getByTestId('commit-select-toggle')->click();

    $page->assertSee('1 selected');
});

test('shift-clicking a second checkbox selects the commits between them', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    $first = commitRow($page, 'Change date format to d/m/Y');
    $last = commitRow($page, 'Add greet function');

    $first->hover();
    $first->getByTestId('commit-select-toggle')->click();

    $last->hover();
    $last->getByTestId('commit-select-toggle')->click(['modifiers' => ['Shift']]);

    $page->assertSee('3 selected');
});

test('clicking Apply with a multi-commit selection navigates to a range URL', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    // Pick the two adjacent newest commits (contiguous range).
    $newest = commitRow($page, 'Change date format to d/m/Y');
    $prev = commitRow($page, 'Add type hints and utils');

    $newest->hover();
    $newest->getByTestId('commit-select-toggle')->click();
    $prev->hover();
    $prev->getByTestId('commit-select-toggle')->click();

    $page->page()->getByRole('button', ['name' => 'Apply'])->click();

    $page->page()->getByPlaceholder('Filter branches...')->waitFor(['state' => 'hidden']);

    $url = $page->page()->url();
    expect($url)->toContain($this->commitHashes[2]);
    expect($url)->toContain($this->commitHashes[1]);
    expect($page->page()->getByLabel('Open selection drawer')->innerText())->toContain('..');
});

test('clearing the selection removes the selected chip', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    $row = commitRow($page, 'Add greet function');
    $row->hover();
    $row->getByTestId('commit-select-toggle')->click();

    $page->assertSee('1 selected');

    $page->page()->getByTitle('Clear selection')->click();

    $page->page()->locator('text=selected')->waitFor(['state' => 'hidden']);
});

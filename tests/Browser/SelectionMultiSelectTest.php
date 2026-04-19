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
    $page = $this->visit($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    $row = commitRow($page, 'Add greet function');
    $row->hover();
    $row->getByTestId('commit-select-toggle')->click();

    $page->assertSee('1 selected');
});

test('shift-clicking a second checkbox selects the commits between them', function () {
    $page = $this->visit($this->projectUrl());
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
    $page = $this->visit($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    $newest = commitRow($page, 'Change date format to d/m/Y');
    $oldest = commitRow($page, 'Add greet function');

    $newest->hover();
    $newest->getByTestId('commit-select-toggle')->click();
    $oldest->hover();
    $oldest->getByTestId('commit-select-toggle')->click();

    $page->page()->getByRole('button', ['name' => 'Apply'])->click();

    $page->page()->getByPlaceholder('Filter branches...')->waitFor(['state' => 'hidden']);

    expect($page->page()->getByLabel('Open selection drawer')->innerText())->toContain('..');
});

test('clearing the selection removes the selected chip', function () {
    $page = $this->visit($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    $row = commitRow($page, 'Add greet function');
    $row->hover();
    $row->getByTestId('commit-select-toggle')->click();

    $page->assertSee('1 selected');

    $page->page()->getByTitle('Clear selection')->click();

    $page->page()->locator('text=selected')->waitFor(['state' => 'hidden']);
});

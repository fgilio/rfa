<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepo();
});

test('checkbox on a commit row toggles it into the selection', function () {
    $page = $this->visit($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    // The per-commit checkbox appears on hover or when already selected.
    $commitRow = $page->page()->locator('div:has(> div > div > div.text-xs:has-text("Add greet function"))')
        ->first();

    $commitRow->hover();
    $commitRow->locator('button[title*="selection"]')->click();

    // "N selected" chip appears in the panel header.
    $page->assertSee('1 selected');
});

test('shift-clicking a second checkbox selects the commits between them', function () {
    $page = $this->visit($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    $firstRow = $page->page()->locator('div:has(> div > div > div.text-xs:has-text("Change date format to d/m/Y"))')->first();
    $lastRow = $page->page()->locator('div:has(> div > div > div.text-xs:has-text("Add greet function"))')->first();

    $firstRow->hover();
    $firstRow->locator('button[title*="selection"]')->click();

    $lastRow->hover();
    $lastRow->locator('button[title*="selection"]')->click(['modifiers' => ['Shift']]);

    // All three commits now selected.
    $page->assertSee('3 selected');
});

test('clicking Apply with a multi-commit selection navigates to a range URL', function () {
    $page = $this->visit($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    $newestRow = $page->page()->locator('div:has(> div > div > div.text-xs:has-text("Change date format to d/m/Y"))')->first();
    $oldestRow = $page->page()->locator('div:has(> div > div > div.text-xs:has-text("Add greet function"))')->first();

    $newestRow->hover();
    $newestRow->locator('button[title*="selection"]')->click();
    $oldestRow->hover();
    $oldestRow->locator('button[title*="selection"]')->click();

    $page->page()->getByRole('button', ['name' => 'Apply'])->click();

    // Wait for navigation away from the drawer (panel closes).
    $page->page()->getByPlaceholder('Filter branches...')->waitFor(['state' => 'hidden']);

    // Selection badge now shows a range (from..to).
    expect($page->page()->getByLabel('Open selection drawer')->innerText())->toContain('..');
});

test('clearing the selection removes the selected chip', function () {
    $page = $this->visit($this->projectUrl());
    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->locator('text=Add greet function')->waitFor();

    $row = $page->page()->locator('div:has(> div > div > div.text-xs:has-text("Add greet function"))')->first();
    $row->hover();
    $row->locator('button[title*="selection"]')->click();

    $page->assertSee('1 selected');

    $page->page()->getByTitle('Clear selection')->click();

    $page->page()->locator('text=selected')->waitFor(['state' => 'hidden']);
});

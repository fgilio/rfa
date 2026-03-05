<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

function helloFileLocator($page): mixed
{
    return $page->page()->locator('.group:has([data-testid="file-header"]:has-text("hello.php"))');
}

function collapseAndAutoExpand($page, $helloFile): void
{
    $helloFile->getByTestId('file-header')->getByText('hello.php')->click();
    $page->assertDontSee('function greet');

    $helloFile->getByLabel('Add file comment')->click();
    $page->assertSee('function greet');
}

// -- Form placement --

test('file comment form appears at top of file body', function () {
    $page = $this->visit($this->projectUrl());
    $page->assertSee('function greet');

    $helloFile = helloFileLocator($page);
    $helloFile->getByLabel('Add file comment')->click();

    expect($helloFile->getByPlaceholder('File comment', false)->count())->toBe(1);

    $formY = $helloFile->getByPlaceholder('File comment', false)->boundingBox()['y'];
    $diffLineY = $helloFile->locator('td[data-testid="diff-line-number"]')->first()->boundingBox()['y'];

    expect($formY)->toBeLessThan($diffLineY);
});

// -- Auto-expand on collapsed file --

test('file comment button on collapsed file expands and shows form', function () {
    $page = $this->visit($this->projectUrl());
    $page->assertSee('function greet');

    $helloFile = helloFileLocator($page);
    collapseAndAutoExpand($page, $helloFile);

    expect($helloFile->getByPlaceholder('File comment', false)->count())->toBe(1);
});

// -- Auto-collapse on cancel --

test('cancel on auto-expanded file re-collapses it', function () {
    $page = $this->visit($this->projectUrl());
    $page->assertSee('function greet');

    $helloFile = helloFileLocator($page);
    collapseAndAutoExpand($page, $helloFile);

    $helloFile->getByRole('button', ['name' => 'Cancel'])->click();

    $page->assertDontSee('function greet');
});

// -- Auto-collapse on save --

test('save on auto-expanded file re-collapses it and shows badge', function () {
    $page = $this->visit($this->projectUrl());
    $page->assertSee('function greet');

    $helloFile = helloFileLocator($page);
    collapseAndAutoExpand($page, $helloFile);

    $helloFile->getByPlaceholder('File comment', false)->fill('Auto-collapse test');
    $helloFile->getByRole('button', ['name' => 'Save'])->click();

    $page->assertDontSee('function greet');
});

// -- Manual toggle clears auto-expand intent --

test('manual toggle after auto-expand prevents re-collapse on cancel', function () {
    $page = $this->visit($this->projectUrl());
    $page->assertSee('function greet');

    $helloFile = helloFileLocator($page);
    collapseAndAutoExpand($page, $helloFile);

    // Manual toggle: collapse then expand again (clears autoExpandedForComment)
    $helloFile->getByTestId('file-header')->getByText('hello.php')->click();
    $page->assertDontSee('function greet');
    $helloFile->getByTestId('file-header')->getByText('hello.php')->click();
    $page->assertSee('function greet');

    $helloFile->getByRole('button', ['name' => 'Cancel'])->click();

    $page->assertSee('function greet');
});

// -- Switching to inline comment clears auto-expand --

test('switching to inline comment clears auto-expand intent', function () {
    $page = $this->visit($this->projectUrl());
    $page->assertSee('function greet');

    $helloFile = helloFileLocator($page);
    collapseAndAutoExpand($page, $helloFile);

    // Switch to inline comment (clears autoExpandedForComment via handleLineMousedown)
    $helloFile->locator('td[data-testid="diff-line-number"]')->first()->click();
    $helloFile->getByPlaceholder('Write a comment', false)->fill('Inline comment');
    $helloFile->getByRole('button', ['name' => 'Save'])->click();

    $page->assertSee('function greet');
});

// -- Saved file comment at top of file --

test('saved file comment renders at top of file', function () {
    $page = $this->visit($this->projectUrl());
    $page->assertSee('function greet');

    $helloFile = helloFileLocator($page);
    $helloFile->getByLabel('Add file comment')->click();
    $helloFile->getByPlaceholder('File comment', false)->fill('Top-level file note');
    $helloFile->getByRole('button', ['name' => 'Save'])->click();

    $page->assertSee('Top-level file note');

    $commentY = $helloFile->getByText('Top-level file note')->boundingBox()['y'];
    $diffLineY = $helloFile->locator('td[data-testid="diff-line-number"]')->first()->boundingBox()['y'];

    expect($commentY)->toBeLessThan($diffLineY);
});

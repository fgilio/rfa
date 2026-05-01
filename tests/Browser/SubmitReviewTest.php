<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->setUpTestRepo();
});

test('submit button disabled when no comments and no global comment', function () {
    $this->visit($this->projectUrl())
        ->assertButtonDisabled('Submit Review');
});

test('submit button enables after adding a comment', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Review comment');
    $page->press('Save');

    $page->assertButtonEnabled('Submit Review');
});

test('submitting shows success state with review submitted', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Looks good');
    $page->press('Save');

    $page->pressAndWaitFor('Submit Review', 3);

    $page->assertSee('Review submitted');
});

test('submitting with only global comment works', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByPlaceholder('Overall review comment', false)->fill('Overall LGTM');
    $page->assertButtonEnabled('Submit Review');
    $page->pressAndWaitFor('Submit Review', 3);

    $page->assertSee('Review submitted');
});

test('export creates rfa directory with md file on disk', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Export test comment');
    $page->press('Save');
    $page->pressAndWaitFor('Submit Review', 3);

    $page->assertSee('Review submitted');

    $rfaDir = $this->testRepoPath.'/.rfa';
    expect(File::isDirectory($rfaDir))->toBeTrue();

    $files = File::glob($rfaDir.'/*');
    expect($files)->toHaveCount(1);

    $mdFile = collect($files)->first(fn ($f) => str_ends_with($f, '.md'));
    expect($mdFile)->not->toBeNull();
});

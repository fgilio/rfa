<?php

use Illuminate\Support\Facades\File;
use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

test('submit button disabled when no comments and no global comment', function () {
    $this->visit($this->projectUrl())
        ->assertButtonDisabled('Submit Review');
});

test('submit button enables after adding a comment', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Review comment');
    $page->press('Save');

    $page->assertButtonEnabled('Submit Review');
});

test('submitting shows success state with review submitted', function () {
    $page = $this->visit($this->projectUrl());

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

test('export creates rfa directory with json and md files on disk', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Export test comment');
    $page->press('Save');
    $page->pressAndWaitFor('Submit Review', 3);

    $page->assertSee('Review submitted');

    // Verify .rfa directory was created with export files
    $rfaDir = $this->testRepoPath.'/.rfa';
    expect(File::isDirectory($rfaDir))->toBeTrue();

    $files = File::glob($rfaDir.'/*');
    expect($files)->toHaveCount(2);

    $jsonFile = collect($files)->first(fn ($f) => str_ends_with($f, '.json'));
    $mdFile = collect($files)->first(fn ($f) => str_ends_with($f, '.md'));

    expect($jsonFile)->not->toBeNull();
    expect($mdFile)->not->toBeNull();
});

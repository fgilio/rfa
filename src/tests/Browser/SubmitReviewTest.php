<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

const CLICK_LINE_SUBMIT = "document.querySelectorAll('td.diff-line-num')[0].click()";

test('submit button disabled when no comments and no global comment', function () {
    $this->visit('/')
        ->assertButtonDisabled('Submit Review');
});

test('submit button enables after adding a comment', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE_SUBMIT);
    $page->type('[placeholder*="Write a comment"]', 'Review comment');
    $page->press('Save');

    $page->assertButtonEnabled('Submit Review');
});

test('submitting shows success state with review submitted', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE_SUBMIT);
    $page->type('[placeholder*="Write a comment"]', 'Looks good');
    $page->press('Save');

    $page->pressAndWaitFor('Submit Review', 3);

    $page->assertSee('Review submitted');
});

test('submitting with only global comment works', function () {
    $page = $this->visit('/');

    // Set global comment directly via Livewire JS API (bypasses wire:model.blur timing issues)
    $page->script("
        const wireId = document.querySelector('[wire\\\\:id]').getAttribute('wire:id');
        Livewire.find(wireId).set('globalComment', 'Overall LGTM');
    ");
    // Wait for Livewire to process (assertButtonEnabled auto-retries ~5s)
    $page->assertButtonEnabled('Submit Review');
    $page->pressAndWaitFor('Submit Review', 3);

    $page->assertSee('Review submitted');
});

test('export creates rfa directory with json and md files on disk', function () {
    $page = $this->visit('/');

    $page->script(CLICK_LINE_SUBMIT);
    $page->type('[placeholder*="Write a comment"]', 'Export test comment');
    $page->press('Save');
    $page->pressAndWaitFor('Submit Review', 3);

    $page->assertSee('Review submitted');

    // Verify .rfa directory was created with export files
    $rfaDir = $this->testRepoPath.'/.rfa';
    expect(is_dir($rfaDir))->toBeTrue();

    $files = glob($rfaDir.'/*');
    expect($files)->toHaveCount(2);

    $jsonFile = collect($files)->first(fn ($f) => str_ends_with($f, '.json'));
    $mdFile = collect($files)->first(fn ($f) => str_ends_with($f, '.md'));

    expect($jsonFile)->not->toBeNull();
    expect($mdFile)->not->toBeNull();
});

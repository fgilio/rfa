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

test('file list loads immediately and diffs load lazily', function () {
    $page = $this->visit('/');

    // Sidebar file names render immediately from metadata
    $page->assertSee('hello.php');
    $page->assertSee('config.php');
    $page->assertSee('utils.php');

    // Diff content loads via x-intersect (auto-retry waits for it)
    $page->assertSee('function greet');
});

test('expanding collapsed file triggers diff load', function () {
    $page = $this->visit('/');

    // Wait for diffs to load before collapsing (prevents race with child Livewire round-trips)
    $page->assertSee('function greet');

    // Collapse all files
    $page->script("
        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'C', shiftKey: true, bubbles: true
        }));
    ");
    $page->assertDontSee('function greet');

    // Expand just the hello.php file via sidebar click
    $page->script("
        const buttons = document.querySelectorAll('aside button');
        // Find the button containing 'hello.php'
        const btn = [...buttons].find(b => b.textContent.includes('hello.php'));
        if (btn) btn.click();
    ");

    // Diff content should load after expand triggers x-intersect
    $page->assertSee('function greet');
});

test('file too large shows warning instead of diff', function () {
    // Add a large file that exceeds the max bytes threshold
    $this->addLargeFile('huge.txt', 600_000);

    // Set a low threshold so the file is considered too large
    config(['rfa.diff_max_bytes' => 500_000]);

    $page = $this->visit('/');

    $page->assertSee('huge.txt');
    $page->assertSee('File diff too large to display');
});

test('export works with lazily loaded diffs', function () {
    $page = $this->visit('/');

    // Wait for diff to load (auto-retry)
    $page->assertSee('function greet');

    // Click first line number to open comment form
    $page->script("document.querySelectorAll('td.diff-line-num')[0].click()");
    $page->type('[placeholder*="Write a comment"]', 'Lazy load export test');
    $page->press('Save');
    $page->assertSee('Lazy load export test');

    $page->pressAndWaitFor('Submit Review', 3);
    $page->assertSee('Review submitted');

    // Verify .rfa directory was created
    $rfaDir = $this->testRepoPath.'/.rfa';
    expect(File::isDirectory($rfaDir))->toBeTrue();

    $files = File::glob($rfaDir.'/*');
    expect($files)->toHaveCount(2);
});

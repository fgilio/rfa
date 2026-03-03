<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

afterEach(function () {
    $this->tearDownTestRepo();
});

// -- Navigation & Context Bar --

test('navigating to commit URL shows context bar with hash and message', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    $page->assertSee($this->commitShortHashes[1]);
    $page->assertSee('Add type hints and utils');
});

test('previous commit button navigates to parent', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    $page->page()->getByLabel('Previous commit')->click();

    $page->assertSee($this->commitShortHashes[0]);
    $page->assertSee('Add greet function');
});

test('next commit button navigates to child', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    $page->page()->getByLabel('Next commit')->click();

    $page->assertSee($this->commitShortHashes[2]);
    $page->assertSee('Change date format to d/m/Y');
});

test('exit button returns to working directory', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    $page->page()->getByLabel('Back to working directory')->click();

    $page->assertDontSee($this->commitShortHashes[1]);
    $page->assertDontSee('Add type hints and utils');
});

test('root commit has no previous button', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[0]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    expect($page->page()->getByLabel('Previous commit')->count())->toBe(0);
});

test('latest commit has no next button', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[2]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    expect($page->page()->getByLabel('Next commit')->count())->toBe(0);
});

test('keyboard [ navigates to previous commit', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    $page->page()->locator('body')->press('[');

    $page->assertSee($this->commitShortHashes[0]);
    $page->assertSee('Add greet function');
});

test('keyboard ] navigates to next commit', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    $page->page()->locator('body')->press(']');

    $page->assertSee($this->commitShortHashes[2]);
    $page->assertSee('Change date format to d/m/Y');
});

// -- Diff Content --

test('commit shows correct file changes', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->assertSee('hello.php');
    $page->assertSee('utils.php');
    $page->assertSee('function greet(string $name): string');
});

test('different commits show different diffs', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[2]);

    $page->assertSee('utils.php');

    // hello.php was not changed in commit 3, so it should not appear in the sidebar
    $sidebarText = $page->script("
        document.querySelector('aside')?.textContent ?? ''
    ");
    expect($sidebarText)->not->toContain('hello.php');
});

// -- Route Edge Cases --

test('short hash in URL resolves to full commit', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitShortHashes[1]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    $page->assertSee($this->commitShortHashes[1]);
    $page->assertSee('Add type hints and utils');
});

test('invalid hash returns 404', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/deadbeefdeadbeef');

    $page->assertSee('404');
});

// -- Branch Explorer Integration --

test('clicking commit in branch explorer navigates to commit view', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl());

    // Open branch explorer by clicking branch badge button
    $page->page()->locator('button:has(span:text("main"))')->click();

    // Wait for commits to load in the panel
    $page->page()->locator('text=Add greet function')->waitFor();

    // Click the first commit row (latest = commit 3)
    $page->page()->locator('text=Change date format to d/m/Y')->click();

    // Assert navigated to commit view
    $page->page()->getByTestId('commit-context-bar')->waitFor();
    $page->assertSee($this->commitShortHashes[2]);
});

// -- Session Isolation --

test('comments in commit mode do not appear in working directory', function () {
    $this->setUpCommitHistoryRepoWithWdChange();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    // Add inline comment in commit mode
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Commit-only note');
    $page->press('Save');
    $page->assertSee('Commit-only note');

    // Navigate to working directory
    $page->page()->getByLabel('Back to working directory')->click();
    $page->assertDontSee('Add type hints and utils');

    $page->assertDontSee('Commit-only note');
});

test('comments in working directory do not appear in commit mode', function () {
    $this->setUpCommitHistoryRepoWithWdChange();

    $page = $this->visit($this->projectUrl());

    // Add inline comment in working directory mode
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('WD-only note');
    $page->press('Save');
    $page->assertSee('WD-only note');

    // Navigate to commit view via fresh visit
    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);
    $page->page()->getByTestId('commit-context-bar')->waitFor();

    $page->assertDontSee('WD-only note');
});

// -- Commit Mode UI State --

test('commit mode hides polling indicator', function () {
    $this->setUpCommitHistoryRepo();

    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->page()->getByTestId('commit-context-bar')->waitFor();
    expect($page->page()->getByTestId('change-polling')->count())->toBe(0);
});

test('empty commit shows correct empty state', function () {
    $this->setUpCommitHistoryRepoWithEmptyCommit();

    // commitHashes[1] is the empty commit
    $page = $this->visit($this->projectUrl().'/c/'.$this->commitHashes[1]);

    $page->assertSee('No file changes in this commit');
});

<?php

use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->setUpTestRepo();
});

afterEach(function () {
    $this->tearDownTestRepo();
});

test('page loads with header showing repo name and file count', function () {
    $repoName = basename($this->testRepoPath);

    $this->visit($this->projectUrl())
        ->assertSee('rfa')
        ->assertSee($repoName)
        ->assertSee('3 files');
});

test('sidebar lists all changed files with correct status badges', function () {
    $page = $this->visit($this->projectUrl());

    $page->assertSee('config.php');
    $page->assertSee('hello.php');
    $page->assertSee('utils.php');

    // Verify status badges via sidebar button text content
    $badgeText = $page->script("
        [...document.querySelectorAll('aside button')].map(b => b.textContent.trim()).join('|')
    ");
    expect($badgeText)->toContain('D');
    expect($badgeText)->toContain('M');
    expect($badgeText)->toContain('A');
});

test('diff shows addition and deletion lines with correct prefixes', function () {
    $this->visit($this->projectUrl())
        ->assertSee('function greet(string $name): string {')
        ->assertSee('function greet($name) {');
});

test('addition and deletion badge counts are correct in header', function () {
    $this->visit($this->projectUrl())
        ->assertSee('+6')
        ->assertSee('-7');
});

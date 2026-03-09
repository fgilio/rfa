<?php

use App\Actions\RegisterProjectAction;
use Illuminate\Support\Facades\File;
use Tests\Browser\Helpers\CreatesTestRepo;

uses(CreatesTestRepo::class);

beforeEach(function () {
    $this->repoPaths = [];

    // Create 3 projects with minimal git repos
    foreach (['alpha-project', 'beta-project', 'gamma-project'] as $name) {
        $path = sys_get_temp_dir().'/rfa_dashboard_'.uniqid().'_'.$name;
        File::makeDirectory($path, 0755, true);
        File::put($path.'/README.md', "# {$name}\n");

        exec('cd '.escapeshellarg($path).' && git init -b main && git config user.email "test@rfa.test" && git config user.name "RFA Test" && git add -A && git commit -m "init" 2>&1');

        // Add uncommitted change so status API returns data
        File::put($path.'/README.md', "# {$name}\nchanged\n");

        app(RegisterProjectAction::class)->handle($path);
        $this->repoPaths[] = $path;
    }
});

afterEach(function () {
    foreach ($this->repoPaths as $path) {
        if ($path !== '' && File::isDirectory($path)) {
            File::deleteDirectory($path);
        }
    }
});

// -- Arrow navigation --

test('arrow down selects first project', function () {
    $page = $this->visit('/');
    $this->pressGlobalKey($page, 'ArrowDown');

    $id = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    expect($id)->not->toBeNull();
});

test('arrow keys cycle through projects', function () {
    $page = $this->visit('/');

    // Down twice, then up once -> should be on first card (index 0)
    $this->pressGlobalKey($page, 'ArrowDown');
    $this->pressGlobalKey($page, 'ArrowDown');
    $this->pressGlobalKey($page, 'ArrowUp');

    $selectedId = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    $firstId = $page->script("document.querySelectorAll('[data-testid=\"project-card\"]')[0]?.dataset.projectId");
    expect($selectedId)->toBe($firstId);
});

test('arrow bounds are clamped', function () {
    $page = $this->visit('/');

    // Press ArrowDown 10 times (only 3 projects)
    for ($i = 0; $i < 10; $i++) {
        $this->pressGlobalKey($page, 'ArrowDown');
    }

    $selectedId = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    $lastId = $page->script("[...document.querySelectorAll('[data-testid=\"project-card\"]')].at(-1)?.dataset.projectId");
    expect($selectedId)->toBe($lastId);
});

test('enter opens selected project', function () {
    $page = $this->visit('/');
    $this->pressGlobalKey($page, 'ArrowDown');
    $this->pressGlobalKey($page, 'Enter');

    $page->page()->waitForURL('**/p/**');
    $url = $page->script('window.location.pathname');
    expect($url)->toContain('/p/');
});

// -- Escape --

test('escape clears selection', function () {
    $page = $this->visit('/');
    $this->pressGlobalKey($page, 'ArrowDown');

    // Verify something is selected
    $before = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    expect($before)->not->toBeNull();

    $this->pressGlobalKey($page, 'Escape');

    $after = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    expect($after)->toBeNull();
});

// -- Search + nav --

test('search and arrow nav on filtered results', function () {
    $page = $this->visit('/');

    // Type a filter that matches only one project
    $page->page()->getByPlaceholder('Filter projects...')->fill('beta');

    // Wait for 150ms debounce + Alpine reactivity to hide non-matching cards
    $page->page()->waitForFunction("document.querySelectorAll('[data-project-card]:not([style*=\"display: none\"])').length === 1");

    $this->pressGlobalKey($page, 'ArrowDown');

    $selectedId = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    expect($selectedId)->not->toBeNull();

    // The visible card should contain "beta"
    $text = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.textContent");
    expect($text)->toContain('beta');
});

// -- Slash to focus --

test('slash focuses search input', function () {
    $page = $this->visit('/');

    // Blur the search input first
    $page->script('document.activeElement?.blur()');

    $this->pressGlobalKey($page, '/');

    $isFocused = $page->script("document.activeElement?.placeholder === 'Filter projects...' || document.activeElement?.closest('[x-ref=\"searchInput\"]') !== null");
    expect($isFocused)->toBe(true);
});

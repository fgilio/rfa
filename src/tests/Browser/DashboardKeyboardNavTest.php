<?php

use App\Actions\RegisterProjectAction;
use App\Models\Project;
use Illuminate\Support\Facades\File;

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
    Project::query()->delete();
});

function pressKey(mixed $page, string $key): void
{
    $page->script(
        'document.dispatchEvent(new KeyboardEvent("keydown", { key: '.json_encode($key).', bubbles: true, cancelable: true }));'
    );
}

// -- Arrow navigation --

test('arrow down selects first project', function () {
    $page = $this->visit('/');
    pressKey($page, 'ArrowDown');

    $id = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    expect($id)->not->toBeNull();
});

test('arrow keys cycle through projects', function () {
    $page = $this->visit('/');

    // Down twice, then up once -> should be on first card (index 0)
    pressKey($page, 'ArrowDown');
    pressKey($page, 'ArrowDown');
    pressKey($page, 'ArrowUp');

    $selectedId = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    $firstId = $page->script("document.querySelectorAll('[data-testid=\"project-card\"]')[0]?.dataset.projectId");
    expect($selectedId)->toBe($firstId);
});

test('arrow bounds are clamped', function () {
    $page = $this->visit('/');

    // Press ArrowDown 10 times in one round-trip (only 3 projects)
    $page->script("
        for (let i = 0; i < 10; i++) {
            document.dispatchEvent(new KeyboardEvent('keydown', {
                key: 'ArrowDown', bubbles: true, cancelable: true
            }));
        }
    ");

    $selectedId = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    $lastId = $page->script("[...document.querySelectorAll('[data-testid=\"project-card\"]')].at(-1)?.dataset.projectId");
    expect($selectedId)->toBe($lastId);
});

test('enter opens selected project', function () {
    $page = $this->visit('/');
    pressKey($page, 'ArrowDown');
    pressKey($page, 'Enter');

    $page->page()->waitForURL('**/p/**');
    $url = $page->script('window.location.pathname');
    expect($url)->toContain('/p/');
});

// -- Escape --

test('escape clears selection', function () {
    $page = $this->visit('/');
    pressKey($page, 'ArrowDown');

    // Verify something is selected
    $before = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    expect($before)->not->toBeNull();

    pressKey($page, 'Escape');

    $after = $page->script("document.querySelector('[data-testid=\"project-card\"].ring-1')?.dataset.projectId");
    expect($after)->toBeNull();
});

// -- Search + nav --

test('search and arrow nav on filtered results', function () {
    $page = $this->visit('/');

    // Type a filter that matches only one project
    $page->page()->getByPlaceholder('Filter projects...')->fill('beta');

    pressKey($page, 'ArrowDown');

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

    pressKey($page, '/');

    $isFocused = $page->script("document.activeElement?.placeholder === 'Filter projects...' || document.activeElement?.closest('[x-ref=\"searchInput\"]') !== null");
    expect($isFocused)->toBe(true);
});

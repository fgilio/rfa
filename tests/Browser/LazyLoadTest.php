<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->setUpTestRepo();
});

test('file list loads immediately and diffs load lazily', function () {
    $page = $this->visit($this->projectUrl());

    // Sidebar file names render immediately from metadata
    $page->assertSee('hello.php');
    $page->assertSee('config.php');
    $page->assertSee('utils.php');

    // Diff content loads via x-intersect (auto-retry waits for it)
    $page->assertSee('function greet');
});

test('renderer readiness waits for visible file shells to settle', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $settled = $page->script('window.rfaRendererReady.settleRenderer(window)');

    expect($settled)->toBeTrue();
    $page->assertNoJavaScriptErrors();

    $hasVisibleBlockers = $page->script(
        'window.rfaRendererReady.hasVisibleRenderBlockers(window)',
    );

    expect($hasVisibleBlockers)->toBeFalse();
});

test('reviews larger than the Livewire request limit still hydrate on demand', function () {
    collect(range(1, 105))->each(function (int $index): void {
        $name = sprintf('bulk-%03d.php', $index);
        File::put($this->testRepoPath.'/'.$name, "<?php\nreturn {$index};\n");
    });

    $page = $this->visitAndLoad($this->projectUrl());
    $shellCount = $page->script("document.querySelectorAll('[data-rfa-render-shell]').length");

    expect($shellCount)->toBeGreaterThan(100);
    $page->assertSee('return 1;');
    $page->assertNoJavaScriptErrors();
});

test('expanding collapsed file triggers diff load', function () {
    $page = $this->visit($this->projectUrl());

    // Wait for diffs to load before collapsing (prevents race with child Livewire round-trips)
    $page->assertSee('function greet');

    // Collapse all files
    $this->pressGlobalKey($page, 'C', ['shiftKey' => true]);
    $page->assertDontSee('function greet');

    // Expand just the hello.php file via sidebar click
    $page->page()->getByRole('button', ['name' => 'hello.php'])->click();

    // Diff content should load after expand triggers x-intersect
    $page->assertSee('function greet');
});

test('file too large shows warning instead of diff', function () {
    // Add a large file that exceeds the max bytes threshold
    $this->addLargeFile('huge.txt', 600_000);

    // Set a low threshold so the file is considered too large
    config(['rfa.diff_max_bytes' => 500_000]);

    $page = $this->visit($this->projectUrl());

    $page->assertSee('huge.txt');
    $page->assertSee('File diff too large to display');
});

test('export works with lazily loaded diffs', function () {
    $page = $this->visit($this->projectUrl());

    // Wait for diff to load (auto-retry)
    $page->assertSee('function greet');

    // Click first line number to open comment form
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Lazy load export test');
    $page->press('Save');
    $page->assertSee('Lazy load export test');

    $page->pressAndWaitFor('Submit review', 3);
    $page->assertSee('Review submitted');

    // Verify .rfa directory was created
    $rfaDir = $this->testRepoPath.'/.rfa';
    expect(File::isDirectory($rfaDir))->toBeTrue();

    $files = File::glob($rfaDir.'/*.md');
    expect($files)->toHaveCount(1);
});

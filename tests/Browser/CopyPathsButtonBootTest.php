<?php

use Illuminate\Support\Facades\File;

/**
 * The copy-paths button's Alpine factory must be registered even when the
 * button's markup first arrives through a Livewire update rather than a fresh
 * document. Regression for "Uncaught ReferenceError: copyPathsButton is not
 * defined" (and the primaryLabel / nameLabel / relativeLabel / fullLabel
 * cascade behind it), caused by loading the script with Blade's per-render
 *
 * @once instead of Livewire's @assets.
 */
beforeEach(function () {
    $this->setUpEmptyTestRepo();
});

test('a copy-paths button delivered by a Livewire update still initializes', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // A clean repo renders no copy-paths button at all, so nothing on this first
    // document pulls the factory in. @assets is what makes it load anyway.
    File::put($this->testRepoPath.'/README.md', "# Test\n\nchanged\n");

    $page->page()->getByLabel('Refresh')->first()->click();
    $page->page()->getByTestId('file-header-copy-path')->first()->waitFor();

    $labelType = $page->page()->evaluate(<<<'JS'
        (() => {
            const el = document.querySelector('[data-testid="file-header-copy-path"]');
            if (!el) return 'no-button';
            try { return typeof Alpine.$data(el).fullLabel; } catch (e) { return 'throw:' + e.message; }
        })()
    JS);

    expect($labelType)->toBe('string');

    $page->assertNoSmoke();
});

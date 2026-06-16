<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

test('inline comments persist after page reload', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Persistent comment');
    $page->press('Save');
    $page->assertSee('Persistent comment');

    $page->refresh();
    $page->assertSee('Persistent comment');
});

test('global comment persists after page reload', function () {
    $page = $this->visit($this->projectUrl());

    // Set global comment directly via Livewire JS API (bypasses wire:model.blur timing issues).
    //
    // We hook the Livewire commit lifecycle BEFORE calling set() so we can wait for the
    // round-trip to actually reach the server and run updatedGlobalComment() -> saveSession().
    // networkidle is unreliable here: Livewire dispatches the commit POST on a later tick, so
    // waitForLoadState('networkidle') can resolve in the gap before the request is even in
    // flight, letting refresh() race the persist. Mirrors the 'reviewed files' test below.
    $page->script(<<<'JS'
        window.__globalCommentPersisted = false;
        window.__globalCommentPendingCommits = 0;

        const wireId = document.querySelector('[data-testid="review-component"]').getAttribute('wire:id');

        Livewire.hook('commit', ({ component, succeed, fail }) => {
            if (component.id !== wireId) return;

            window.__globalCommentPendingCommits++;

            // Only a SUCCESSFUL commit proves the value persisted. A failed commit must
            // NOT satisfy the wait — otherwise it masks the real failure behind a refresh
            // and a confusing value mismatch instead of a clear timeout.
            succeed(() => {
                window.__globalCommentPendingCommits--;
                window.__globalCommentPersisted = true;
            });
            fail(() => {
                window.__globalCommentPendingCommits--;
            });
        });

        Livewire.find(wireId).set('globalComment', 'Global persisted note');
    JS);

    $page->page()->waitForFunction('window.__globalCommentPersisted === true && window.__globalCommentPendingCommits === 0');

    $page->refresh();

    $value = $page->page()->getByPlaceholder('Overall review comment', false)->inputValue();
    expect($value)->toBe('Global persisted note');
});

test('reviewed files persist after page reload', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script(<<<'JS'
        window.__reviewedPersisted = false;
        window.__reviewedPendingCommits = 0;

        const wireId = document.querySelector('[data-testid="review-component"]').getAttribute('wire:id');

        Livewire.hook('commit', ({ component, succeed, fail }) => {
            if (component.id !== wireId) return;

            window.__reviewedPendingCommits++;

            // Only a SUCCESSFUL commit proves the toggle persisted; a failed commit must
            // not satisfy the wait (it would refresh against unsaved state).
            succeed(() => {
                window.__reviewedPendingCommits--;
                window.__reviewedPersisted = true;
            });
            fail(() => {
                window.__reviewedPendingCommits--;
            });
        });
    JS);

    $page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->first()->click();
    // The reviewed-counter island re-renders on the toggle round-trip;
    // poll until it renders.
    $page->page()->waitForFunction("document.querySelector('[data-testid=\"reviewed-counter\"]')?.textContent?.includes('1/3 reviewed')");
    $page->page()->waitForFunction('window.__reviewedPersisted === true && window.__reviewedPendingCommits === 0');

    $page->refresh();
    $page->page()->waitForFunction("document.querySelector('[data-testid=\"reviewed-counter\"]')?.textContent?.includes('1/3 reviewed')");
});

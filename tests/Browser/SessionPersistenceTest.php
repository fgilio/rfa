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

    // Set global comment directly via Livewire JS API (bypasses wire:model.blur timing issues)
    $page->script("
        const wireId = document.querySelector('[data-testid=\"review-component\"]').getAttribute('wire:id');
        Livewire.find(wireId).set('globalComment', 'Global persisted note');
    ");
    // Livewire.set() updates client state optimistically, so checking the textarea value
    // can race the server commit. Wait for the Livewire POST to drain so saveSession()
    // has actually persisted before we refresh.
    $page->waitForEvent('networkidle');

    $page->refresh();

    $value = $page->page()->getByPlaceholder('Overall review comment', false)->inputValue();
    expect($value)->toBe('Global persisted note');
})->flaky();

test('reviewed files persist after page reload', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->script(<<<'JS'
        window.__reviewedPersisted = false;
        window.__reviewedPendingCommits = 0;

        const wireId = document.querySelector('[data-testid="review-component"]').getAttribute('wire:id');

        Livewire.hook('commit', ({ component, succeed, fail }) => {
            if (component.id !== wireId) return;

            window.__reviewedPendingCommits++;

            const done = () => {
                window.__reviewedPendingCommits--;
                window.__reviewedPersisted = true;
            };

            succeed(done);
            fail(done);
        });
    JS);

    $page->page()->getByRole('checkbox', ['name' => 'Reviewed'])->first()->click();
    // Alpine updates the counter on the next microtask; poll until it renders.
    $page->page()->waitForFunction("document.querySelector('[data-testid=\"reviewed-counter\"]')?.textContent?.includes('1/3 reviewed')");
    $page->page()->waitForFunction('window.__reviewedPersisted === true && window.__reviewedPendingCommits === 0');

    $page->refresh();
    $page->page()->waitForFunction("document.querySelector('[data-testid=\"reviewed-counter\"]')?.textContent?.includes('1/3 reviewed')");
});

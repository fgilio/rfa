<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->setUpTestRepo();
});

// -- Esc behavior --

test('single esc on empty comment form closes it', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->assertSee('Cancel');

    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertDontSee('Cancel');
});

test('single esc with content shows draft hint', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Some text');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('Press Esc again to save as draft');
    $page->assertSee('Cancel');
});

test('double esc with content saves as draft', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Draft text');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertDontSee('Cancel');
    $page->assertSee('Draft');
    $page->assertSee('Draft text');
});

test('esc hint disappears after timeout', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Timeout test');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('Press Esc again to save as draft');

    // Hint auto-clears after 1.5s; assertDontSee retries until gone
    $page->assertDontSee('Press Esc again to save as draft');

    // Form still open
    $page->assertSee('Cancel');
});

test('single esc with content does not close form', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Keep me');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('Cancel');
    expect($page->page()->getByPlaceholder('Write a comment', false)->inputValue())->toBe('Keep me');
});

// -- Draft rendering --

test('draft comment shows Draft badge', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Badge test');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('Draft');
    $page->assertSee('Badge test');
});

test('draft comment exposes an edit button', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Click me');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('Click me');

    // Edit button is the explicit affordance (same as finalized comments)
    $page->page()->getByRole('button', ['name' => 'Edit comment'])->first()->click();

    $page->assertSee('Cancel');
});

// -- Click-to-edit drafts --

test('clicking edit on a draft re-opens form with pre-filled text', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Original draft');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('Original draft');

    $page->page()->getByRole('button', ['name' => 'Edit comment'])->first()->click();

    $page->assertSee('Cancel');
    expect($page->page()->getByPlaceholder('Write a comment', false)->inputValue())->toBe('Original draft');
});

test('saving edited draft promotes to finalized comment', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Promote me');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->page()->getByRole('button', ['name' => 'Edit comment'])->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Final comment');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Meta+Enter');

    $page->assertSee('Final comment');
    // Draft badge should be gone - the comment is now finalized
    expect($page->page()->getByTestId('draft-comment')->count())->toBe(0);
});

test('clearing draft text and pressing esc deletes the draft', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Delete me');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('Delete me');

    $page->page()->getByRole('button', ['name' => 'Edit comment'])->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertDontSee('Delete me');
    $page->page()->getByTestId('draft-comment')->waitFor(['state' => 'detached', 'timeout' => 5000]);
});

test('double esc on edited draft updates the draft', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('v1');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('v1');

    $page->page()->getByRole('button', ['name' => 'Edit comment'])->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('v2');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('v2');
    $page->assertSee('Draft');
});

// -- Re-open draft on line click --

test('clicking line with existing draft re-opens it', function () {
    $page = $this->visit($this->projectUrl());

    // Pin BOTH clicks to one named file. An unscoped getByTestId('diff-line-number')
    // ->first() is an unstable locator: it re-resolves on every action, and while the
    // changed files lazy-load their diffs (x-intersect), the "first" line on the page
    // flips to whichever file rendered its rows first. So the initial click could create
    // the draft on one file while the re-click lands on a *different* file (which has no
    // draft) — the mousedown handler then misses the draft, falls through to the drag
    // path, and opens a BLANK form that no later poll can recover. Scoping to hello.php
    // makes both clicks resolve to the same line that actually owns the draft.
    $helloFile = $page->page()->locator('.group:has([data-testid="file-header"]:has-text("hello.php"))');
    $lineNum = $helloFile->getByTestId('diff-line-number')->first();

    $lineNum->click();
    $helloFile->getByPlaceholder('Write a comment', false)->fill('Existing draft');
    $helloFile->getByPlaceholder('Write a comment', false)->press('Escape');
    $helloFile->getByPlaceholder('Write a comment', false)->press('Escape');

    // The re-open mousedown handler reads $wire.fileComments synchronously to find the
    // existing draft, so the draft must be committed into this file's client state before
    // we re-click. The draft badge is server-rendered from $fileComments, so once it
    // appears the updateComments commit has landed and the synchronous read will see it.
    $helloFile->getByTestId('draft-comment')->first()->waitFor();

    // Re-click the same line — now guaranteed to hit the existing-draft branch.
    $lineNum->click();

    // editComment() sets formBody synchronously, but x-model flushes to the textarea on
    // the next Alpine tick — wait (in-browser, polled) until the value hydrates.
    $input = $helloFile->getByPlaceholder('Write a comment', false);
    $input->waitFor(['state' => 'visible']);
    $page->page()->waitForFunction(<<<'JS'
        () => {
            const ta = [...document.querySelectorAll('textarea')].find(t => (t.placeholder || '').startsWith('Write a comment'));
            return !!ta && ta.value === 'Existing draft';
        }
    JS);

    expect($input->inputValue())->toBe('Existing draft');
});

// -- File-level drafts --

test('file comment supports draft via double esc', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByLabel('Add file comment')->first()->click();
    $page->page()->getByPlaceholder('File comment', false)->fill('File draft');
    $page->page()->getByPlaceholder('File comment', false)->press('Escape');
    $page->page()->getByPlaceholder('File comment', false)->press('Escape');

    $page->assertSee('File draft');
    $page->assertSee('Draft');
});

// -- Submit bar --

test('submit bar shows draft count separately', function () {
    $page = $this->visit($this->projectUrl());

    // Create finalized comment
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Final comment');
    $page->press('Save');

    // Create draft comment on a different line
    $page->page()->getByTestId('diff-line-number')->nth(4)->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Draft comment');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('1 comment');
    $page->assertSee('1 draft');
});

test('drafts alone do not enable submit button', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Only a draft');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('1 draft');
    $page->assertButtonDisabled('Submit review');
});

test('submit with drafts arms an inline confirm', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // Create finalized comment (this is what enables the submit button)
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Keep me');
    $page->press('Save');

    // Create draft
    $page->page()->getByTestId('diff-line-number')->nth(4)->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Draft');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    // Wait for draft to be saved to Livewire state before submitting
    $page->assertSee('1 draft');

    // Drafts use an inline arm-to-confirm (no native confirm dialog): the first
    // click warns that the draft will be excluded and waits for a second click.
    $page->press('Submit review');
    $page->assertSee('Submit without 1 draft?');
    $page->assertDontSee('Review submitted');
});

// -- Persistence --

test('draft comments persist after page reload', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Persistent draft');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('Persistent draft');

    $page->refresh();

    $page->assertSee('Persistent draft');
    $page->assertSee('Draft');
});

// -- Export exclusion --

test('drafts excluded from export', function () {
    $page = $this->visit($this->projectUrl());

    // Create finalized comment
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Include me');
    $page->press('Save');

    // Create draft on a different line
    $page->page()->getByTestId('diff-line-number')->nth(4)->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Exclude me');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    // Wait for the draft to sync into Livewire state so the first submit click
    // arms (draftCount > 0) rather than submitting immediately.
    $page->assertSee('1 draft');

    // Drafts use an inline arm-to-confirm: first click arms, second submits.
    $page->press('Submit review');
    $page->pressAndWaitFor('Submit without 1 draft?', 3);
    $page->assertSee('Review submitted');

    $rfaDir = $this->testRepoPath.'/.rfa';
    $files = File::glob($rfaDir.'/*.md');
    $md = File::get($files[0]);

    expect($md)->toContain('Include me');
    expect($md)->not->toContain('Exclude me');
});

// -- Confirm dialog cancel path --

test('canceling the armed draft submit does not submit review', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    // Create finalized comment (enables submit)
    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Stay here');
    $page->press('Save');

    // Create draft
    $page->page()->getByTestId('diff-line-number')->nth(4)->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Draft too');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    $page->assertSee('1 draft');

    // Arm the inline confirm, then cancel by clicking outside the submit button
    // (@click.outside resets `armed`). The review must not submit and the button
    // reverts to its normal label.
    $page->press('Submit review');
    $page->assertSee('Submit without 1 draft?');
    $page->page()->getByPlaceholder('Overall review comment', false)->click();

    $page->assertSee('Submit review');
    $page->assertDontSee('Review submitted');
    $page->assertSee('Stay here');
});

// -- Esc propagation (regression guard) --

test('esc in comment textarea does not blur or trigger global shortcuts', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('diff-line-number')->first()->click();
    $page->page()->getByPlaceholder('Write a comment', false)->fill('Guard test');
    $page->page()->getByPlaceholder('Write a comment', false)->press('Escape');

    // Textarea should still be focused (form still open)
    $page->assertSee('Cancel');
    expect($page->page()->getByPlaceholder('Write a comment', false)->inputValue())->toBe('Guard test');
});

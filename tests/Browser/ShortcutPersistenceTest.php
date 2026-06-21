<?php

beforeEach(function () {
    $this->setUpCommitHistoryRepo();
});

// The shortcuts-help component lives in the persistent layout chrome, so its
// x-init runs once and is not re-run when Livewire morphs the body. The keymap
// store clears every binding on `livewire:navigating`, so without an explicit
// re-registration on `livewire:navigated` the global `?` (and ⌘↵ save) would go
// dead after the first SPA navigation.
test('the global help shortcut survives a Livewire navigation', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByTestId('review-component')->first()->waitFor();

    $hasHelpBinding = "Alpine.store('keymap').bindings.has('?')";

    // Registered on the initial page load.
    $page->page()->waitForFunction($hasHelpBinding);
    expect($page->page()->evaluate($hasHelpBinding))->toBeTrue();

    // SPA-navigate to a commit page; the binding is cleared on navigating and
    // must be re-registered on navigated.
    $page->page()->evaluate("Livewire.navigate('".$this->projectUrl().'/c/'.$this->commitHashes[1]."')");
    $page->page()->getByTestId('commit-context-bar')->waitFor();

    $page->page()->waitForFunction($hasHelpBinding);
    expect($page->page()->evaluate($hasHelpBinding))->toBeTrue();
});

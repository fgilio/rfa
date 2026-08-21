<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->setUpTestRepo();

    File::put($this->testRepoPath.'/CLAUDE.md', "# Project rules\n\nBe concise.\n");
    $this->runTestRepoCommand($this->testRepoPath, 'git add -A');
    $this->commitTestRepo($this->testRepoPath, 'Add agent context');
});

test('the context page renders a context file without JavaScript errors', function () {
    $page = $this->visitAndLoad($this->projectUrl().'/context');

    $page->page()->getByTestId('file-header')->first()->waitFor();

    $page->assertSee('CLAUDE.md')->assertNoSmoke();
});

<?php

use App\Models\Project;

beforeEach(function () {
    $this->setUpCommitHistoryRepo();
    $this->runTestRepoCommand($this->testRepoPath, "git branch dev {$this->commitHashes[0]}");

    Project::where('slug', $this->testProjectSlug)
        ->update(['default_base_branch' => 'dev']);
});

test('reopening the selection drawer refreshes commit rows and since-base count together', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByLabel('Open selection drawer')->click();
    $page->page()->getByTestId('since-base-row')->getByText('Since dev')->waitFor();
    $page->page()->getByText('Change date format to d/m/Y')->waitFor();
    $page->script('Alpine.$data(document.querySelector("[x-data*=branchExplorer]")).closePanel()');

    $this->runTestRepoCommand($this->testRepoPath, <<<'SH'
        echo "fresh 1" >> fresh.txt
        git add -A
        git commit -m "Fresh commit 1" -q
        echo "fresh 2" >> fresh.txt
        git add -A
        git commit -m "Fresh commit 2" -q
    SH);

    $page->page()->getByLabel('Open selection drawer')->click();

    $page->page()->getByText('Fresh commit 2')->waitFor();
    $page->page()->getByText('4 commits + uncommitted changes')->waitFor();
    expect($page->page()->getByTestId('since-base-row')->innerText())->toContain('4 commits');

    $page->page()->getByTestId('since-base-row')->click();
    $page->page()->getByText('WT+4')->waitFor();
    expect($page->page()->getByText('WT+4')->innerText())->toBe('WT+4');

    $page->page()->getByRole('button', ['name' => 'Apply working tree + 4 commits'])->click();

    $expectedPath = '/p/'.$this->testProjectSlug.'/rw/'.$this->commitHashes[0];
    $path = '';
    for ($i = 0; $i < 50; $i++) {
        $path = $page->script('window.location.pathname');
        if ($path === $expectedPath) {
            break;
        }

        usleep(100_000);
    }

    expect($path)->toBe($expectedPath);
});

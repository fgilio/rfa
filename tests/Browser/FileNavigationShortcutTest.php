<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

const REVIEW_ROOT = '[data-testid="review-component"]';

function activeFileExpression(): string
{
    return 'Alpine.$data(document.querySelector(\''.REVIEW_ROOT.'\')).activeFile';
}

test('j and k move the active file selection between visible files', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('review-component')->first()->waitFor();

    // The visible file list is hydrated client-side from a data attribute, so
    // wait for it before reading the ordered ids the shortcuts navigate.
    $page->page()->waitForFunction(<<<'JS'
        () => {
            const root = document.querySelector('[data-testid="review-component"]');
            if (!root) return false;
            const data = Alpine.$data(root);
            return data.visibleFileEntries.length >= 2 && data.activeFile != null;
        }
    JS);

    $ids = $page->page()->evaluate(
        'Alpine.$data(document.querySelector(\''.REVIEW_ROOT.'\')).visibleFileEntries.map(f => f.id)'
    );

    expect(count($ids))->toBeGreaterThanOrEqual(2);

    $initial = $page->page()->evaluate(activeFileExpression());

    // The server seeds the selection to the first visible file.
    expect(array_search($initial, $ids, true))->toBe(0);

    $page->page()->locator('body')->press('j');
    $page->page()->waitForFunction(activeFileExpression().' === '.json_encode($ids[1]));

    $page->page()->locator('body')->press('k');
    $page->page()->waitForFunction(activeFileExpression().' === '.json_encode($initial));

    expect($page->page()->evaluate(activeFileExpression()))->toBe($initial);
});

test('k holds at the first file and does not wrap', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('review-component')->first()->waitFor();

    $page->page()->waitForFunction(<<<'JS'
        () => {
            const root = document.querySelector('[data-testid="review-component"]');
            if (!root) return false;
            const data = Alpine.$data(root);
            return data.visibleFileEntries.length >= 2 && data.activeFile != null;
        }
    JS);

    $initial = $page->page()->evaluate(activeFileExpression());

    // Already on the first file: pressing k twice must clamp, not wrap to the end.
    $page->page()->locator('body')->press('k');
    $page->page()->locator('body')->press('k');

    expect($page->page()->evaluate(activeFileExpression()))->toBe($initial);
});

test('j and k recover to an end when no visible file is active', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('review-component')->first()->waitFor();

    $page->page()->waitForFunction(<<<'JS'
        () => {
            const root = document.querySelector('[data-testid="review-component"]');
            if (!root) return false;
            const data = Alpine.$data(root);
            return data.visibleFileEntries.length >= 2 && data.activeFile != null;
        }
    JS);

    $ids = $page->page()->evaluate(
        'Alpine.$data(document.querySelector(\''.REVIEW_ROOT.'\')).visibleFileEntries.map(f => f.id)'
    );
    $first = $ids[0];
    $last = $ids[count($ids) - 1];

    $clearActive = 'Alpine.$data(document.querySelector(\''.REVIEW_ROOT.'\')).activeFile = "__no_such_file__"';

    // Selection points at a file that is no longer visible: k recovers to the last entry.
    $page->page()->evaluate($clearActive);
    $page->page()->locator('body')->press('k');
    $page->page()->waitForFunction(activeFileExpression().' === '.json_encode($last));

    // ...and j recovers to the first entry.
    $page->page()->evaluate($clearActive);
    $page->page()->locator('body')->press('j');
    $page->page()->waitForFunction(activeFileExpression().' === '.json_encode($first));
});

test('the selection follows the server clamp when a filter hides the active file', function () {
    $page = $this->visitAndLoad($this->projectUrl());

    $page->page()->getByTestId('review-component')->first()->waitFor();

    $page->page()->waitForFunction(<<<'JS'
        () => {
            const root = document.querySelector('[data-testid="review-component"]');
            if (!root) return false;
            const data = Alpine.$data(root);
            return data.visibleFileEntries.length >= 2 && data.activeFile != null;
        }
    JS);

    // Select a file the upcoming filter will hide (its path lacks "hello").
    $hiddenId = $page->page()->evaluate(<<<'JS'
        (() => {
            const data = Alpine.$data(document.querySelector('[data-testid="review-component"]'));
            const target = data.visibleFileEntries.find(entry => !entry.path.includes('hello'));
            data.activeFile = target.id;
            return target.id;
        })()
    JS);

    expect($page->page()->evaluate(activeFileExpression()))->toBe($hiddenId);

    // Filter down to only hello.php, dropping the selected file from the list.
    $page->page()->getByPlaceholder('Filter files...')->fill('hello');

    // The morph would leave activeFile on the now-hidden file; the commit sync
    // moves it onto a still-visible row instead.
    $page->page()->waitForFunction(<<<'JS'
        () => {
            const data = Alpine.$data(document.querySelector('[data-testid="review-component"]'));
            const ids = data.visibleFileEntries.map(entry => entry.id);
            return ids.length >= 1 && ids.includes(data.activeFile);
        }
    JS);

    expect($page->page()->evaluate(activeFileExpression()))->not->toBe($hiddenId);
});

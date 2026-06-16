<?php

beforeEach(function () {
    $this->setUpTestRepo();
});

/**
 * Wait for at least one diff line to render. `visitAndLoad()` only waits for
 * networkidle, which can fire before the lazy-loaded diff has filled the DOM.
 * The page-search tests fill the input and expect matches immediately, so
 * they need a deterministic signal that the content they're about to search
 * is actually present before pressing Cmd+F.
 */
function waitForDiffsLoaded($page): void
{
    $page->page()->getByTestId('diff-line-number')->first()->waitFor();
}

test('Cmd+F opens the search bar, Escape closes it and clears marks', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    waitForDiffsLoaded($page);

    $page->page()->getByPlaceholder('Find...')->waitFor(['state' => 'hidden']);

    $page->page()->locator('body')->press('Meta+f');

    $input = $page->page()->getByPlaceholder('Find...');
    $input->waitFor();
    $input->fill('greet');

    $page->page()->locator('.rfa-search-match')->first()->waitFor();
    expect($page->page()->locator('.rfa-search-match')->count())->toBeGreaterThan(0);

    $input->press('Escape');

    $page->page()->getByPlaceholder('Find...')->waitFor(['state' => 'hidden']);
    expect($page->page()->locator('.rfa-search-match')->count())->toBe(0);
});

test('typing highlights every match, marks the first as current, and shows the counter', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    waitForDiffsLoaded($page);

    $page->page()->locator('body')->press('Meta+f');
    $input = $page->page()->getByPlaceholder('Find...');
    $input->waitFor();
    $input->fill('greet');

    $page->page()->locator('.rfa-search-match')->first()->waitFor();

    $total = $page->page()->locator('.rfa-search-match')->count();
    expect($total)->toBeGreaterThan(1);
    expect($page->page()->locator('.rfa-search-match--current')->count())->toBe(1);

    $page->assertSee('1 of '.$total);

    $badge = $page->page()->locator('.rfa-search-match--current')->first()->getAttribute('data-match-number');
    expect($badge)->toBe('1 of '.$total);
});

test('Enter advances and Shift+Enter goes back, wrapping at the ends', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    waitForDiffsLoaded($page);

    $page->page()->locator('body')->press('Meta+f');
    $input = $page->page()->getByPlaceholder('Find...');
    $input->waitFor();
    $input->fill('greet');

    $page->page()->locator('.rfa-search-match')->first()->waitFor();
    $total = $page->page()->locator('.rfa-search-match')->count();

    $page->assertSee('1 of '.$total);

    $input->press('Enter');
    $page->assertSee('2 of '.$total);

    $input->press('Shift+Enter');
    $page->assertSee('1 of '.$total);

    $input->press('Shift+Enter');
    $page->assertSee($total.' of '.$total);

    $input->press('Enter');
    $page->assertSee('1 of '.$total);
});

test('non-matching query shows No results and wraps no nodes', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    waitForDiffsLoaded($page);

    $page->page()->locator('body')->press('Meta+f');
    $input = $page->page()->getByPlaceholder('Find...');
    $input->waitFor();
    $input->fill('zzzzz-no-match-for-this-token');

    $page->assertSee('No results');
    expect($page->page()->locator('.rfa-search-match')->count())->toBe(0);
});

test('the search bar itself is skipped so the counter text is never wrapped', function () {
    $page = $this->visitAndLoad($this->projectUrl());
    waitForDiffsLoaded($page);

    $page->page()->locator('body')->press('Meta+f');
    $input = $page->page()->getByPlaceholder('Find...');
    $input->waitFor();
    $input->fill('greet');

    $page->page()->locator('.rfa-search-match')->first()->waitFor();

    $input->fill('of');
    $insideBar = $page->page()->locator('[data-search-ignore] .rfa-search-match')->count();
    expect($insideBar)->toBe(0);
});

test('escapeRegex via markMatches handles regex metacharacters without throwing', function () {
    $page = $this->visit($this->projectUrl());

    $result = $page->script(<<<'JS'
        (() => {
            const host = document.createElement('div');
            host.id = '__search_sandbox';
            host.textContent = 'a.b a+b a*b a(b a.b';
            document.body.appendChild(host);

            const root = document.querySelector('[x-data="pageSearch"]');
            const data = Alpine.$data(root);

            data.query = 'a.b';
            data.refresh();
            const literalMatches = document.querySelectorAll('#__search_sandbox .rfa-search-match').length;

            data.query = 'a+b';
            data.refresh();
            const plusMatches = document.querySelectorAll('#__search_sandbox .rfa-search-match').length;

            data.close();
            host.remove();

            return { literalMatches, plusMatches };
        })()
    JS);

    // "a.b" literal should match exactly 2 occurrences (not every 3-char
    // substring that would match if '.' were treated as regex).
    expect($result['literalMatches'])->toBe(2);
    expect($result['plusMatches'])->toBe(1);
});

test('markMatches is case-insensitive', function () {
    $page = $this->visit($this->projectUrl());

    $result = $page->script(<<<'JS'
        (() => {
            const host = document.createElement('div');
            host.id = '__search_sandbox_ci';
            host.textContent = 'Hello hello HELLO hEllO';
            document.body.appendChild(host);

            const root = document.querySelector('[x-data="pageSearch"]');
            const data = Alpine.$data(root);

            data.query = 'hello';
            data.refresh();
            const count = document.querySelectorAll('#__search_sandbox_ci .rfa-search-match').length;

            data.close();
            host.remove();

            return count;
        })()
    JS);

    expect($result)->toBe(4);
});

test('refresh() clears old marks before running the new query', function () {
    $page = $this->visit($this->projectUrl());

    $result = $page->script(<<<'JS'
        (() => {
            const host = document.createElement('div');
            host.id = '__search_sandbox_refresh';
            host.textContent = 'alpha beta alpha gamma alpha';
            document.body.appendChild(host);

            const root = document.querySelector('[x-data="pageSearch"]');
            const data = Alpine.$data(root);

            data.query = 'alpha';
            data.refresh();
            const first = document.querySelectorAll('#__search_sandbox_refresh .rfa-search-match').length;

            data.query = 'beta';
            data.refresh();
            const second = document.querySelectorAll('#__search_sandbox_refresh .rfa-search-match').length;
            const oldAlphaStillWrapped = host.innerHTML.includes('class="rfa-search-match">alpha');

            data.close();
            host.remove();

            return { first, second, oldAlphaStillWrapped };
        })()
    JS);

    expect($result['first'])->toBe(3);
    expect($result['second'])->toBe(1);
    expect($result['oldAlphaStillWrapped'])->toBeFalse();
});

test('close() restores original text so no trace is left in the DOM', function () {
    $page = $this->visit($this->projectUrl());

    $result = $page->script(<<<'JS'
        (() => {
            const host = document.createElement('div');
            host.id = '__search_sandbox_close';
            const original = 'one two one three one';
            host.textContent = original;
            document.body.appendChild(host);

            const root = document.querySelector('[x-data="pageSearch"]');
            const data = Alpine.$data(root);

            data.query = 'one';
            data.refresh();
            const wrappedCount = document.querySelectorAll('#__search_sandbox_close .rfa-search-match').length;

            data.close();
            const afterClose = document.querySelectorAll('#__search_sandbox_close .rfa-search-match').length;
            const textPreserved = host.textContent;
            const singleTextNode = host.childNodes.length === 1 && host.childNodes[0].nodeType === Node.TEXT_NODE;

            host.remove();

            return { wrappedCount, afterClose, textPreserved, singleTextNode, original };
        })()
    JS);

    expect($result['wrappedCount'])->toBe(3);
    expect($result['afterClose'])->toBe(0);
    expect($result['textPreserved'])->toBe($result['original']);
    expect($result['singleTextNode'])->toBeTrue();
});

test('markMatches skips text inside display:none and [hidden] sections', function () {
    $page = $this->visit($this->projectUrl());

    $result = $page->script(<<<'JS'
        (() => {
            const host = document.createElement('div');
            host.id = '__search_sandbox_hidden';

            const visible = document.createElement('div');
            visible.textContent = 'hiddentoken';
            host.appendChild(visible);

            const displayNone = document.createElement('div');
            displayNone.style.display = 'none';
            displayNone.textContent = 'hiddentoken';
            host.appendChild(displayNone);

            const hiddenAttr = document.createElement('div');
            hiddenAttr.hidden = true;
            hiddenAttr.textContent = 'hiddentoken';
            host.appendChild(hiddenAttr);

            document.body.appendChild(host);

            const root = document.querySelector('[x-data="pageSearch"]');
            const data = Alpine.$data(root);

            data.query = 'hiddentoken';
            data.refresh();

            const total = document.querySelectorAll('#__search_sandbox_hidden .rfa-search-match').length;
            const inDisplayNone = displayNone.querySelectorAll('.rfa-search-match').length;
            const inHiddenAttr = hiddenAttr.querySelectorAll('.rfa-search-match').length;

            data.close();
            host.remove();

            return { total, inDisplayNone, inHiddenAttr };
        })()
    JS);

    expect($result['total'])->toBe(1);
    expect($result['inDisplayNone'])->toBe(0);
    expect($result['inHiddenAttr'])->toBe(0);
});

test('current match badge text tracks navigation', function () {
    $page = $this->visit($this->projectUrl());

    $result = $page->script(<<<'JS'
        (() => {
            const host = document.createElement('div');
            host.id = '__search_sandbox_badge';
            host.textContent = 'xxbadgexx xxbadgexx xxbadgexx';
            document.body.appendChild(host);

            const root = document.querySelector('[x-data="pageSearch"]');
            const data = Alpine.$data(root);

            data.query = 'xxbadgexx';
            data.refresh();

            const badgeAt = () => {
                const current = document.querySelector('#__search_sandbox_badge .rfa-search-match--current');
                return current ? current.getAttribute('data-match-number') : null;
            };
            const currentCount = () =>
                document.querySelectorAll('#__search_sandbox_badge .rfa-search-match--current').length;

            const initial = badgeAt();
            const currents = currentCount();
            data.find(false);
            const afterNext = badgeAt();
            data.find(true);
            const afterPrev = badgeAt();

            data.close();
            host.remove();

            return { initial, currents, afterNext, afterPrev };
        })()
    JS);

    expect($result['currents'])->toBe(1);
    expect($result['initial'])->toBe('1 of 3');
    expect($result['afterNext'])->toBe('2 of 3');
    expect($result['afterPrev'])->toBe('1 of 3');
});

test('a match crossing a token boundary paints the pill once, centered, with no phantom box', function () {
    $page = $this->visit($this->projectUrl());

    $result = $page->script(<<<'JS'
        (() => {
            const host = document.createElement('div');
            host.id = '__search_sandbox_phantom';
            // Two inline spans on one line, so "aaaaaabbbbbb" matches across the
            // boundary — the same shape as a query straddling syntax-highlight
            // tokens in a diff (e.g. "workflow (`Aoi").
            host.innerHTML = '<span>aaaaaa</span><span>bbbbbb</span>';
            document.body.appendChild(host);

            const root = document.querySelector('[x-data="pageSearch"]');
            const data = Alpine.$data(root);

            data.query = 'aaaaaabbbbbb';
            data.refresh();

            const spans = host.querySelectorAll('.rfa-search-match');
            const first = spans[0].getBoundingClientRect();
            const last = spans[spans.length - 1].getBoundingClientRect();

            const afterFirst = getComputedStyle(spans[0], '::after').content;
            const afterSecond = getComputedStyle(spans[spans.length - 1], '::after').content;
            const center = parseFloat(spans[0].style.getPropertyValue('--rfa-match-center'));

            data.close();
            host.remove();

            return {
                pieces: spans.length,
                afterFirst,
                afterSecond,
                center,
                expectedCenter: (last.right - first.left) / 2,
                firstHalf: first.width / 2,
            };
        })()
    JS);

    // The match really does cross the boundary into a second piece.
    expect($result['pieces'])->toBe(2);

    // The numbered piece paints the pill; the crossed piece renders no ::after
    // box at all (pre-fix it painted an empty `""` phantom pill).
    expect($result['afterFirst'])->not->toBe('none')
        ->and($result['afterFirst'])->not->toBe('""')
        ->and($result['afterSecond'])->toBe('none');

    // The pill anchors to the midpoint of the whole match, not the first piece.
    expect($result['center'])->toEqualWithDelta($result['expectedCenter'], 1.0)
        ->and($result['center'])->toBeGreaterThan($result['firstHalf']);
});

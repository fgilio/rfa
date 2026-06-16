import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import pageSearch from '../../public/js/page-search.js';

const { escapeRegex, createPageSearch, install } = pageSearch;

// happy-dom does not implement Element#checkVisibility, but markMatches's
// TreeWalker filter calls it on every text-node parent. Without a polyfill
// every walk returns zero matches and the suite can't exercise production
// logic. Stub it with a style-string scan covering the cases we test
// (display:none, [hidden]). Real Chromium uses computed style; happy-dom
// has no layout engine so a true polyfill isn't possible here.
beforeAll(() => {
    Element.prototype.checkVisibility = function () {
        let el = this;
        while (el && el.nodeType === 1) {
            if (el.hasAttribute('hidden')) return false;
            const style = el.getAttribute('style') || '';
            if (/display\s*:\s*none/i.test(style)) return false;
            if (/visibility\s*:\s*hidden/i.test(style)) return false;
            if (/opacity\s*:\s*0(?!\.)/i.test(style)) return false;
            el = el.parentElement;
        }
        return true;
    };
});

afterAll(() => {
    delete Element.prototype.checkVisibility;
});

function buildPageSearchHarness() {
    const data = createPageSearch();
    data.$nextTick = (cb) => cb();
    data.$refs = { input: null };
    return data;
}

describe('escapeRegex', () => {
    it.each([
        ['foo', 'foo'],
        ['foo.bar', 'foo\\.bar'],
        ['a+b', 'a\\+b'],
        ['(test)', '\\(test\\)'],
        ['$^|?*', '\\$\\^\\|\\?\\*'],
        // Input is a single backslash; expected output is two escaped backslashes.
        ['no\\backslash', 'no\\\\backslash'],
    ])('escapes %j as %j', (input, expected) => {
        expect(escapeRegex(input)).toBe(expected);
    });
});

describe('markMatches', () => {
    let data;

    beforeEach(() => {
        data = buildPageSearchHarness();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('returns one group per match, each group is an array of one or more spans', () => {
        document.body.innerHTML = '<p>the cat sat on the cat mat</p>';

        const matches = data.markMatches('cat');

        expect(matches).toHaveLength(2);
        matches.forEach((spans) => {
            expect(spans).toHaveLength(1);
            expect(spans[0].tagName).toBe('SPAN');
            expect(spans[0].classList.contains('rfa-search-match')).toBe(true);
            expect(spans[0].textContent).toBe('cat');
        });
        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(2);
    });

    it('matches case-insensitively and preserves original casing in each span', () => {
        document.body.innerHTML = '<p>Cat cat CAT</p>';

        const matches = data.markMatches('Cat');

        expect(matches).toHaveLength(3);
        expect(matches.map(([span]) => span.textContent)).toEqual(['Cat', 'cat', 'CAT']);
    });

    it('skips text inside script, style, input, textarea, and [data-search-ignore]', () => {
        document.body.innerHTML = `
            <p>visible cat one</p>
            <script>var s = 'cat in script';</script>
            <style>.cat { color: red; }</style>
            <input value="cat in input">
            <textarea>cat in textarea</textarea>
            <div data-search-ignore>cat ignored</div>
            <p>visible cat two</p>
        `;

        const matches = data.markMatches('cat');

        // Only the two visible <p> matches should be wrapped.
        expect(matches).toHaveLength(2);
        expect(matches[0][0].closest('p').textContent).toBe('visible cat one');
        expect(matches[1][0].closest('p').textContent).toBe('visible cat two');
    });

    it('skips text inside elements with display:none', () => {
        document.body.innerHTML = `
            <p>visible cat</p>
            <p style="display: none">hidden cat</p>
        `;

        const matches = data.markMatches('cat');

        expect(matches).toHaveLength(1);
        expect(matches[0][0].closest('p').textContent).toBe('visible cat');
    });

    it('escapes special regex chars in the query', () => {
        document.body.innerHTML = '<p>literal . here, but xyz should not match</p>';

        const matches = data.markMatches('.');

        // Only the literal period matches; '.' as a regex would match every char.
        expect(matches).toHaveLength(1);
        expect(matches[0][0].textContent).toBe('.');
    });

    it('matches a query that spans sibling syntax-highlighter token spans', () => {
        // Phiki renders `'local'` as three sibling token spans, each with its
        // own text node. The old per-text-node search couldn't match across
        // that boundary; this guards against regressing.
        document.body.innerHTML = "<p><span>'</span><span>local</span><span>'</span></p>";

        const matches = data.markMatches("'local'");

        expect(matches).toHaveLength(1);
        expect(matches[0]).toHaveLength(3);
        expect(matches[0].map((s) => s.textContent).join('')).toBe("'local'");
        // One logical match, but rendered as three pieces (one per crossed text node).
        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(3);
    });

    it('does not match across block-level boundaries', () => {
        // Joining text across separate <p>s would let "ab" match across lines.
        document.body.innerHTML = '<p>a</p><p>b</p>';

        const matches = data.markMatches('ab');

        expect(matches).toHaveLength(0);
        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(0);
    });
});

describe('refresh', () => {
    let data;

    beforeEach(() => {
        data = buildPageSearchHarness();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('treats a whitespace-only query as empty (no spans, currentMatch reset)', () => {
        document.body.innerHTML = '<p>cat sat on the mat</p>';
        data.query = '   ';

        data.refresh();

        expect(data.matches).toHaveLength(0);
        expect(data.currentMatch).toBe(0);
        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(0);
    });
});

describe('find', () => {
    let data;

    beforeEach(() => {
        data = buildPageSearchHarness();
        document.body.innerHTML = '<p>cat one</p><p>cat two</p><p>cat three</p>';
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('wraps forward at the last match and backward at the first', () => {
        data.query = 'cat';
        data.refresh();

        expect(data.matches).toHaveLength(3);
        expect(data.currentMatch).toBe(1);

        data.find(false);
        expect(data.currentMatch).toBe(2);

        data.find(false);
        expect(data.currentMatch).toBe(3);

        // Wrap forward 3 -> 1.
        data.find(false);
        expect(data.currentMatch).toBe(1);

        // Wrap backward 1 -> 3.
        data.find(true);
        expect(data.currentMatch).toBe(3);

        data.find(true);
        expect(data.currentMatch).toBe(2);
    });

    it('triggers a refresh when matches is empty but query is set', () => {
        data.query = 'cat';
        // Note: no refresh() call before find(). matches starts as [].
        expect(data.matches).toHaveLength(0);

        data.find(false);

        expect(data.matches).toHaveLength(3);
        expect(data.currentMatch).toBe(1);
    });

    it('is a no-op when query is empty and matches is empty', () => {
        data.query = '';

        data.find(false);

        expect(data.matches).toHaveLength(0);
        expect(data.currentMatch).toBe(0);
        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(0);
    });
});

describe('clearMarks', () => {
    let data;

    beforeEach(() => {
        data = buildPageSearchHarness();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('round-trips: textContent restored, no spans, parent text nodes merged', () => {
        document.body.innerHTML = '<p>the cat sat on the cat mat</p>';
        const p = document.querySelector('p');
        const originalText = p.textContent;
        const originalChildCount = p.childNodes.length;

        data.markMatches('cat');

        // After marking: text/span/text/span/text — 5 child nodes.
        expect(p.childNodes.length).toBeGreaterThan(originalChildCount);
        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(2);

        data.clearMarks();

        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(0);
        expect(p.textContent).toBe(originalText);
        // normalize() should merge adjacent text nodes back into a single text node.
        expect(p.childNodes.length).toBe(originalChildCount);
        expect(data.matches).toEqual([]);
    });

    it('round-trips a cross-text-node match without leaving stray spans', () => {
        const original = "<span>'</span><span>local</span><span>'</span>";
        document.body.innerHTML = `<p>${original}</p>`;

        data.markMatches("'local'");
        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(3);

        data.clearMarks();

        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(0);
        expect(document.querySelector('p').innerHTML).toBe(original);
    });
});

describe('close', () => {
    let data;

    beforeEach(() => {
        data = buildPageSearchHarness();
        document.body.innerHTML = '<p>cat sat on the mat</p>';
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('clears marks and resets open/query/currentMatch', () => {
        data.open = true;
        data.query = 'cat';
        data.refresh();

        expect(data.matches.length).toBeGreaterThan(0);
        expect(data.currentMatch).toBe(1);

        data.close();

        expect(data.open).toBe(false);
        expect(data.query).toBe('');
        expect(data.currentMatch).toBe(0);
        expect(document.querySelectorAll('.rfa-search-match')).toHaveLength(0);
    });
});

describe('updateCurrent', () => {
    let data;

    beforeEach(() => {
        Element.prototype.scrollIntoView = vi.fn();
        data = buildPageSearchHarness();
        document.body.innerHTML = '<p>cat one</p><p>cat two</p><p>cat three</p>';
    });

    afterEach(() => {
        document.body.innerHTML = '';
        delete Element.prototype.scrollIntoView;
    });

    it('writes the "X of Y" badge as data-match-number on the current match only', () => {
        data.query = 'cat';
        data.refresh();

        // After refresh, currentMatch === 1; badge should be on the first match.
        expect(data.matches[0][0].getAttribute('data-match-number')).toBe('1 of 3');
        expect(data.matches[1][0].hasAttribute('data-match-number')).toBe(false);
        expect(data.matches[2][0].hasAttribute('data-match-number')).toBe(false);

        data.find(false);

        expect(data.matches[0][0].hasAttribute('data-match-number')).toBe(false);
        expect(data.matches[1][0].getAttribute('data-match-number')).toBe('2 of 3');
        expect(data.matches[2][0].hasAttribute('data-match-number')).toBe(false);
    });

    it('toggles the rfa-search-match--current class to track the current match', () => {
        data.query = 'cat';
        data.refresh();

        expect(data.matches[0][0].classList.contains('rfa-search-match--current')).toBe(true);
        expect(data.matches[1][0].classList.contains('rfa-search-match--current')).toBe(false);

        data.find(false);

        expect(data.matches[0][0].classList.contains('rfa-search-match--current')).toBe(false);
        expect(data.matches[1][0].classList.contains('rfa-search-match--current')).toBe(true);
    });

    it('toggles the current class on every span of a multi-piece match', () => {
        document.body.innerHTML = "<p><span>'</span><span>local</span><span>'</span></p>";
        data.query = "'local'";
        data.refresh();

        expect(data.matches).toHaveLength(1);
        const [spans] = data.matches;
        expect(spans).toHaveLength(3);

        spans.forEach((span) => {
            expect(span.classList.contains('rfa-search-match--current')).toBe(true);
        });
        // Badge sits on the first piece only so the "X of Y" indicator
        // doesn't render once per crossed token span.
        expect(spans[0].getAttribute('data-match-number')).toBe('1 of 1');
        expect(spans[1].hasAttribute('data-match-number')).toBe(false);
        expect(spans[2].hasAttribute('data-match-number')).toBe(false);
    });

    it('centers the badge across a multi-piece match via --rfa-match-center', () => {
        document.body.innerHTML = "<p><span>'</span><span>local</span><span>'</span></p>";
        data.query = "'local'";
        data.refresh();

        const [spans] = data.matches;
        expect(spans).toHaveLength(3);

        // happy-dom reports zero-size rects, so stub the pieces onto one line
        // laid left-to-right: 0–10, 10–60, 60–70 (px). The whole match spans
        // 0..70, so the center offset from the first piece's left edge is 35px.
        const lefts = [0, 10, 60];
        const rights = [10, 60, 70];
        spans.forEach((span, i) => {
            span.getBoundingClientRect = () => ({
                top: 0, bottom: 10, left: lefts[i], right: rights[i],
                width: rights[i] - lefts[i], height: 10,
            });
        });
        data.updateCurrent(false);

        // 35px is past the first piece's own center (5px) — proving the badge
        // anchors to the whole match, not just the first token span.
        expect(spans[0].style.getPropertyValue('--rfa-match-center')).toBe('35px');
    });

    it('leaves --rfa-match-center unset for a single-piece match so CSS falls back to 50%', () => {
        data.query = 'cat';
        data.refresh();

        // Each "cat" is a single text node, so centerBadge sees one span and
        // sets no offset; CSS centers on the piece itself via `left: 50%`.
        expect(data.matches[0]).toHaveLength(1);
        expect(data.matches[0][0].style.getPropertyValue('--rfa-match-center')).toBe('');
    });
});

describe('install', () => {
    afterEach(() => {
        delete window.Alpine;
        delete window.__pageSearchAttached;
    });

    it('registers pageSearch with Alpine and is idempotent', () => {
        const dataFn = vi.fn();
        window.Alpine = { data: dataFn };

        expect(install(window)).toBe(true);
        expect(dataFn).toHaveBeenCalledTimes(1);
        expect(dataFn).toHaveBeenCalledWith('pageSearch', expect.any(Function));

        expect(install(window)).toBe(false);
        expect(dataFn).toHaveBeenCalledTimes(1);
    });

    it('is a no-op when Alpine is not present', () => {
        expect(install(window)).toBe(false);
    });

    it('does not poison the attached flag when called before Alpine loads', () => {
        expect(install(window)).toBe(false);
        expect(window.__pageSearchAttached).toBeUndefined();

        const dataFn = vi.fn();
        window.Alpine = { data: dataFn };
        expect(install(window)).toBe(true);
        expect(dataFn).toHaveBeenCalledTimes(1);
    });
});

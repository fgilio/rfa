import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import diffFile from '../../public/js/diff-file.js';

const { getScrollSpeed, extractLineSnippet, install } = diffFile;

describe('getScrollSpeed', () => {
    // Coherent fixture: viewport 800px tall, sticky header consumes 50px,
    // edge zone is 70px deep at top and bottom.
    const base = { viewportHeight: 800, headerBottom: 50, edgeZone: 70 };

    it.each([
        // Above sticky header — strictly `<` headerBottom returns max-up.
        [30, -600],
        [49, -600],
    ])('returns max-up (-600) for y=%i (above sticky header)', (y, expected) => {
        expect(getScrollSpeed({ ...base, y })).toBe(expected);
    });

    it('treats y === headerBottom as the top of the edge zone (depth=1, full speed)', () => {
        // At equality `y < headerBottom` is false but `y < headerBottom + edgeZone` is true,
        // so depth = 1 - 0/70 = 1, velocity = -(100 + 500) = -600.
        expect(getScrollSpeed({ ...base, y: 50 })).toBe(-600);
    });

    it.each([
        [85, -350],   // halfway through the top edge zone
        [800, 600],   // at viewport bottom: depth = 1, max down
    ])('returns exact velocity for y=%i', (y, expected) => {
        expect(getScrollSpeed({ ...base, y })).toBe(expected);
    });

    it.each([
        // Just inside top zone: depth = 1 - 69/70.
        [119, -(100 + (1 - 69 / 70) * 500)],
        // Just inside bottom zone (731 > 730): depth = 1 - 69/70.
        [731, 100 + (1 - 69 / 70) * 500],
        // Mid bottom zone: depth = 1 - 30/70.
        [770, 100 + (1 - 30 / 70) * 500],
    ])('returns proportional velocity for y=%i', (y, expected) => {
        expect(getScrollSpeed({ ...base, y })).toBeCloseTo(expected, 6);
    });

    it.each([
        // At top zone exit: `y < headerBottom + edgeZone` is `120 < 120` → false.
        [120],
        // Mid neutral.
        [400],
        // At bottom zone start: `y > viewportHeight - edgeZone` is `730 > 730` → false.
        [730],
    ])('returns 0 at neutral / boundary y=%i', (y) => {
        expect(getScrollSpeed({ ...base, y })).toBe(0);
    });

    it('does not clamp when cursor leaves the window vertically', () => {
        // Deliberate: when y exceeds viewportHeight, depth > 1 and velocity > 600.
        // Caller relies on this to keep accelerating off-window.
        const expected = 100 + (1 - -50 / 70) * 500; // ≈ 957.14
        expect(getScrollSpeed({ ...base, y: 850 })).toBeCloseTo(expected, 6);
        expect(getScrollSpeed({ ...base, y: 850 })).toBeGreaterThan(600);
    });

    describe('with no cached header (headerBottom = 0)', () => {
        it('treats y=30 as inside the top edge zone, not above the header', () => {
            // y < 0 is false → falls into the second branch with depth = 1 - 30/70.
            const expected = -(100 + (1 - 30 / 70) * 500);
            expect(getScrollSpeed({ ...base, headerBottom: 0, y: 30 })).toBeCloseTo(expected, 6);
        });

        it('returns -600 only for negative y (above the viewport)', () => {
            expect(getScrollSpeed({ ...base, headerBottom: 0, y: -1 })).toBe(-600);
        });
    });
});

describe('extractLineSnippet', () => {
    let root;

    beforeEach(() => {
        document.body.innerHTML = `
            <table id="root">
                <tr data-line-old="10" data-line-new="10"><td class="num">10</td><td class="content">first old line</td></tr>
                <tr data-line-old="11" data-line-new="11"><td class="num">11</td><td class="content">second line</td></tr>
                <tr data-line-old="12"><td class="num">12</td><td class="content">left-only line</td></tr>
                <tr data-line-new="13"><td class="num">13</td><td class="content">right-only line</td></tr>
            </table>
        `;
        root = document.getElementById('root');
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('returns a single line when endLine is null', () => {
        expect(extractLineSnippet({ root, side: 'left', startLine: 10, endLine: null }))
            .toBe('first old line');
    });

    it('joins a left-side range with newlines', () => {
        expect(extractLineSnippet({ root, side: 'left', startLine: 10, endLine: 11 }))
            .toBe('first old line\nsecond line');
    });

    it('extracts a right-side single line', () => {
        expect(extractLineSnippet({ root, side: 'right', startLine: 13, endLine: 13 }))
            .toBe('right-only line');
    });

    it('reads each row by side-specific attribute (left includes left-only rows)', () => {
        expect(extractLineSnippet({ root, side: 'left', startLine: 11, endLine: 12 }))
            .toBe('second line\nleft-only line');
    });

    it('returns null when no rows match the side attribute', () => {
        // No `tr[data-line-new="12"]` exists — that row is left-only.
        expect(extractLineSnippet({ root, side: 'right', startLine: 12, endLine: 12 }))
            .toBeNull();
    });

    it('skips out-of-range numbers when the range extends past EOF', () => {
        expect(extractLineSnippet({ root, side: 'left', startLine: 10, endLine: 999 }))
            .toBe('first old line\nsecond line\nleft-only line');
    });

    it('returns null for null startLine', () => {
        expect(extractLineSnippet({ root, side: 'left', startLine: null, endLine: null }))
            .toBeNull();
    });

    it('returns null for file-level comments (side="file")', () => {
        expect(extractLineSnippet({ root, side: 'file', startLine: 5, endLine: 5 }))
            .toBeNull();
    });

    it('handles reversed ranges via Math.min/max on the bounds', () => {
        expect(extractLineSnippet({ root, side: 'left', startLine: 12, endLine: 10 }))
            .toBe('first old line\nsecond line\nleft-only line');
    });

    it('trimEnd strips trailing whitespace from the joined snippet only at the end', () => {
        document.body.innerHTML = `
            <table id="ws-root">
                <tr data-line-old="1"><td>num</td><td>has   interior   spaces</td></tr>
                <tr data-line-old="2"><td>num</td><td>trailing on last line   </td></tr>
            </table>
        `;
        const wsRoot = document.getElementById('ws-root');
        const result = extractLineSnippet({ root: wsRoot, side: 'left', startLine: 1, endLine: 2 });
        // Interior whitespace preserved; trailing whitespace on the last line trimmed.
        expect(result).toBe('has   interior   spaces\ntrailing on last line');
    });
});

describe('install', () => {
    afterEach(() => {
        delete window.Alpine;
        delete window.__diffFileAttached;
    });

    it('registers the diffFile Alpine factory and is idempotent', () => {
        const data = vi.fn();
        window.Alpine = { data };

        expect(install(window)).toBe(true);
        expect(data).toHaveBeenCalledTimes(1);
        expect(data).toHaveBeenCalledWith('diffFile', expect.any(Function));

        expect(install(window)).toBe(false);
        expect(data).toHaveBeenCalledTimes(1);
    });

    it('returns false when Alpine is not present', () => {
        expect(install(window)).toBe(false);
    });
});

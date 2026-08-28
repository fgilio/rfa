import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import diffFile from '../../public/js/diff-file.js';

const {
    getScrollSpeed,
    extractLineSnippet,
    trimTrailingUrlPunctuation,
    urlMatchesInText,
    urlMatchAtTextOffset,
    urlAtTextOffset,
    urlMatchAtPoint,
    urlAtClick,
    rangeForUrlMatch,
    rangeContainsPoint,
    urlMatchForKeyboardControl,
    installKeyboardUrlControls,
    showUrlHighlight,
    clearUrlHighlight,
    expanderToRefocus,
    createLinePoint,
    areLinePointsEqual,
    rowContainsLinePoint,
    formatCitation,
    selectionSourceText,
    closestDiffLine,
    selectionLineRange,
    createDiffFile,
    install,
    autoInstall,
} = diffFile;

describe('Cmd+click URLs', () => {
    const cssApi = document.defaultView.CSS;
    const rangePrototype = document.defaultView.Range.prototype;
    const originalGetClientRects = Object.getOwnPropertyDescriptor(rangePrototype, 'getClientRects');
    const originalGetBoundingClientRect = Object.getOwnPropertyDescriptor(rangePrototype, 'getBoundingClientRect');

    beforeEach(() => {
        Object.defineProperty(rangePrototype, 'getClientRects', {
            configurable: true,
            value: vi.fn(() => [{ left: 0, right: 200, top: 0, bottom: 100, width: 200, height: 100 }]),
        });
        Object.defineProperty(rangePrototype, 'getBoundingClientRect', {
            configurable: true,
            value: vi.fn(() => ({ left: 20, right: 180, top: 50, bottom: 70, width: 160, height: 20 })),
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        delete document.caretPositionFromPoint;
        delete document.caretRangeFromPoint;
        Object.defineProperty(document.defaultView, 'CSS', { configurable: true, value: cssApi });
        delete document.defaultView.Highlight;
        if (originalGetClientRects) {
            Object.defineProperty(rangePrototype, 'getClientRects', originalGetClientRects);
        } else {
            delete rangePrototype.getClientRects;
        }
        if (originalGetBoundingClientRect) {
            Object.defineProperty(rangePrototype, 'getBoundingClientRect', originalGetBoundingClientRect);
        } else {
            delete rangePrototype.getBoundingClientRect;
        }
        document.body.innerHTML = '';
        delete globalThis.Alpine;
    });

    it.each([
        ['sentence punctuation', 'https://example.com/report).', 'https://example.com/report'],
        ['balanced parentheses', 'https://example.com/a_(b)', 'https://example.com/a_(b)'],
        ['nested unmatched closers', 'https://example.com/foo)]', 'https://example.com/foo'],
        ['query and fragment', 'https://example.com/report?q=one&sort=asc#result', 'https://example.com/report?q=one&sort=asc#result'],
    ])('removes only URL-adjacent %s', (_, candidate, expected) => {
        expect(trimTrailingUrlPunctuation(candidate)).toBe(expected);
    });

    it('finds the URL under the text offset', () => {
        const text = 'Quote at https://redsentry.com/contact, then continue.';
        const start = text.indexOf('https://');

        expect(urlAtTextOffset(text, text.indexOf('redsentry'))).toBe('https://redsentry.com/contact');
        expect(urlMatchAtTextOffset(text, text.indexOf('redsentry'))).toEqual({
            url: 'https://redsentry.com/contact',
            start,
            end: start + 'https://redsentry.com/contact'.length,
        });
        expect(urlAtTextOffset(text, text.indexOf('continue'))).toBeNull();
        expect(urlAtTextOffset(text, start + 'https://redsentry.com/contact'.length)).toBeNull();
        expect(urlMatchesInText(text)).toEqual([{
            url: 'https://redsentry.com/contact',
            start,
            end: start + 'https://redsentry.com/contact'.length,
        }]);
    });

    it('resolves a URL when syntax highlighting splits it across spans', () => {
        document.body.innerHTML = `
            <div class="diff-cell-content">
                <span>Open https://red</span><span>sentry.com/contact</span><span>.</span>
            </div>
        `;
        const target = document.querySelectorAll('.diff-cell-content span')[1];
        const textNode = target.firstChild;
        document.caretPositionFromPoint = vi.fn(() => ({
            offsetNode: textNode,
            offset: textNode.textContent.indexOf('contact'),
        }));

        const event = {
            metaKey: true,
            button: 0,
            target,
            clientX: 100,
            clientY: 20,
        };

        expect(urlAtClick(event)).toBe('https://redsentry.com/contact');
    });

    it('keeps Markdown table cells separate while resolving URLs', () => {
        document.body.innerHTML = `
            <div class="diff-cell-content">
                <div class="diff-md-table">
                    <div class="diff-md-td">https://example.com/docs</div>
                    <div class="diff-md-td">status</div>
                </div>
            </div>
        `;
        const target = document.querySelector('.diff-md-td');
        document.caretPositionFromPoint = vi.fn(() => ({ offsetNode: target.firstChild, offset: 12 }));
        const event = { metaKey: true, button: 0, target, clientX: 40, clientY: 20 };

        const match = urlMatchAtPoint(event);

        expect(match.url).toBe('https://example.com/docs');
        expect(match.cell).toBe(target);
        expect(urlAtClick(event)).toBe('https://example.com/docs');

        installKeyboardUrlControls(document.body);
        expect(document.querySelectorAll('[data-diff-url-control]')).toHaveLength(1);
        expect(document.querySelector('[data-diff-url-control]').dataset.diffUrl).toBe('https://example.com/docs');
    });

    it('activates only points inside the rendered URL glyphs', () => {
        const url = 'https://example.com/docs';
        document.body.innerHTML = `<div class="diff-cell-content">${url},</div>`;
        const target = document.querySelector('.diff-cell-content');
        document.caretPositionFromPoint = vi.fn(() => ({ offsetNode: target.firstChild, offset: url.length }));
        rangePrototype.getClientRects.mockReturnValue([
            { left: 10, right: 100, top: 10, bottom: 30, width: 90, height: 20 },
        ]);

        expect(urlMatchAtPoint({ target, clientX: 99, clientY: 20 })?.url).toBe(url);
        expect(urlMatchAtPoint({ target, clientX: 110, clientY: 20 })).toBeNull();
        expect(urlMatchAtPoint({ target, clientX: 180, clientY: 20 })).toBeNull();

        const range = rangeForUrlMatch({ url, start: 0, end: url.length, cell: target });
        expect(rangeContainsPoint(range, 99, 20)).toBe(true);
        expect(rangeContainsPoint(range, 100, 20)).toBe(false);
        expect(rangeContainsPoint(range, 110, 20)).toBe(false);
    });

    it('highlights the exact URL range across syntax spans', () => {
        document.body.innerHTML = `
            <div class="diff-cell-content">
                <span>Open https://red</span><span>sentry.com/contact</span><span>, then continue</span>
            </div>
        `;
        const cell = document.querySelector('.diff-cell-content');
        const target = cell.children[1];
        document.caretPositionFromPoint = vi.fn(() => ({
            offsetNode: target.firstChild,
            offset: target.textContent.indexOf('contact'),
        }));
        const match = urlMatchAtPoint({ target, clientX: 100, clientY: 20 });
        const range = rangeForUrlMatch(match);
        const highlights = { set: vi.fn(), delete: vi.fn() };
        const view = cell.ownerDocument.defaultView;
        Object.defineProperty(view, 'CSS', { configurable: true, value: { highlights } });
        Object.defineProperty(view, 'Highlight', {
            configurable: true,
            value: vi.fn(function (highlightedRange) {
                this.range = highlightedRange;
            }),
        });

        expect(range.toString()).toBe('https://redsentry.com/contact');
        expect(showUrlHighlight(match)).toBe(true);
        expect(highlights.set).toHaveBeenCalledWith('rfa-hovered-diff-url', expect.objectContaining({ range }));

        clearUrlHighlight(document);

        expect(highlights.delete).toHaveBeenCalledWith('rfa-hovered-diff-url');
    });

    it('requires Cmd and a primary-button click inside diff content', () => {
        document.body.innerHTML = '<div class="diff-cell-content">https://example.com</div>';
        const target = document.querySelector('.diff-cell-content');
        document.caretPositionFromPoint = vi.fn(() => ({ offsetNode: target.firstChild, offset: 10 }));

        expect(urlAtClick({ metaKey: false, button: 0, target, clientX: 1, clientY: 1 })).toBeNull();
        expect(urlAtClick({ metaKey: true, button: 1, target, clientX: 1, clientY: 1 })).toBeNull();
        expect(urlAtClick({ metaKey: true, button: 0, target: document.body, clientX: 1, clientY: 1 })).toBeNull();
    });

    it('opens the resolved URL through Livewire and consumes the click', () => {
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
        document.body.innerHTML = '<div class="diff-cell-content">https://example.com/docs</div>';
        const target = document.querySelector('.diff-cell-content');
        document.caretPositionFromPoint = vi.fn(() => ({ offsetNode: target.firstChild, offset: 12 }));
        const component = createDiffFile({ fileId: 'file-1', filePath: 'README.md', isReviewed: false });
        component.$wire = { openExternalUrl: vi.fn() };
        const event = {
            metaKey: true,
            button: 0,
            target,
            clientX: 1,
            clientY: 1,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        };

        component.openUrlAtClick(event);

        expect(component.$wire.openExternalUrl).toHaveBeenCalledWith('https://example.com/docs');
        expect(event.preventDefault).toHaveBeenCalledOnce();
        expect(event.stopPropagation).toHaveBeenCalledOnce();
    });

    it('previews only the URL under the pointer and clears it on leave', () => {
        vi.useFakeTimers();
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
        document.body.innerHTML = '<div id="root"><div class="diff-cell-content">Read https://example.com/docs now</div></div>';
        const root = document.getElementById('root');
        const target = document.querySelector('.diff-cell-content');
        document.caretPositionFromPoint = vi.fn(() => ({ offsetNode: target.firstChild, offset: 16 }));
        const highlights = { set: vi.fn(), delete: vi.fn() };
        const view = target.ownerDocument.defaultView;
        Object.defineProperty(view, 'CSS', { configurable: true, value: { highlights } });
        Object.defineProperty(view, 'Highlight', {
            configurable: true,
            value: vi.fn(function (range) {
                this.range = range;
            }),
        });
        const component = createDiffFile({ fileId: 'file-1', filePath: 'README.md', isReviewed: false });
        component.$root = root;
        const event = { target, clientX: 1, clientY: 1 };

        component.previewUrlAtPoint(event);
        component.previewUrlAtPoint(event);

        expect(component.hoveredUrl).toBe('https://example.com/docs');
        expect(highlights.set).toHaveBeenCalledOnce();
        expect(component.urlHintVisible).toBe(false);

        vi.advanceTimersByTime(350);

        expect(component.urlHintVisible).toBe(true);
        expect(component.urlHintLeft).toBe(13);
        expect(component.urlHintTop).toBe(19);

        component.clearUrlPreview();

        expect(component.hoveredUrl).toBeNull();
        expect(component.urlHintVisible).toBe(false);
        expect(highlights.delete).toHaveBeenCalled();

        vi.useRealTimers();
    });

    it('cancels the Cmd-click hint when the pointer leaves before the delay', () => {
        vi.useFakeTimers();
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
        document.body.innerHTML = '<div id="root"><div class="diff-cell-content">https://example.com</div></div>';
        const root = document.getElementById('root');
        const target = document.querySelector('.diff-cell-content');
        document.caretPositionFromPoint = vi.fn(() => ({ offsetNode: target.firstChild, offset: 10 }));
        const component = createDiffFile({ fileId: 'file-1', filePath: 'README.md', isReviewed: false });
        component.$root = root;

        component.previewUrlAtPoint({ target, clientX: 10, clientY: 50 });
        component.clearUrlPreview();
        vi.advanceTimersByTime(350);

        expect(component.urlHintVisible).toBe(false);
        expect(component.urlHintTimer).toBeNull();

        vi.useRealTimers();
    });

    it('creates focusable URL actions with Enter-key parity', () => {
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
        document.body.innerHTML = '<div id="root"><div class="diff-cell-content">Read https://example.com/docs now</div></div>';
        const root = document.getElementById('root');
        const highlights = { set: vi.fn(), delete: vi.fn() };
        Object.defineProperty(document.defaultView, 'CSS', { configurable: true, value: { highlights } });
        Object.defineProperty(document.defaultView, 'Highlight', {
            configurable: true,
            value: vi.fn(function (range) {
                this.range = range;
            }),
        });

        installKeyboardUrlControls(root, 'url-hint-file-1');
        installKeyboardUrlControls(root, 'url-hint-file-1');

        const control = root.querySelector('[data-diff-url-control]');
        const match = urlMatchForKeyboardControl(control);
        expect(root.querySelectorAll('[data-diff-url-control]')).toHaveLength(1);
        expect(control.tagName).toBe('BUTTON');
        expect(control.getAttribute('aria-label')).toBe('Open https://example.com/docs in the system browser');
        expect(control.getAttribute('aria-describedby')).toBe('url-hint-file-1');
        expect(match.url).toBe('https://example.com/docs');
        expect(match.cell).toBe(root.querySelector('.diff-cell-content'));

        const component = createDiffFile({
            fileId: 'file-1',
            filePath: 'README.md',
            isReviewed: false,
            urlHintId: 'url-hint-file-1',
        });
        component.$root = root;
        component.$wire = { openExternalUrl: vi.fn() };
        component.previewUrlForKeyboard({ target: control });

        expect(component.hoveredUrl).toBe('https://example.com/docs');
        expect(component.urlHintMode).toBe('keyboard');
        expect(component.urlHintVisible).toBe(true);
        expect(highlights.set).toHaveBeenCalledOnce();

        const event = {
            target: control,
            detail: 0,
            preventDefault: vi.fn(),
            stopPropagation: vi.fn(),
        };
        component.openUrlAtClick(event);

        expect(component.$wire.openExternalUrl).toHaveBeenCalledWith('https://example.com/docs');
        expect(event.preventDefault).toHaveBeenCalledOnce();
        expect(event.stopPropagation).toHaveBeenCalledOnce();

        component.clearUrlPreviewAfterFocus({ relatedTarget: null });
        expect(component.urlHintVisible).toBe(false);
    });
});

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
            <div id="root">
                <div class="diff-line" data-line-old="10" data-line-new="10"><div class="diff-cell-num">10</div><div class="diff-cell-content">first old line</div></div>
                <div class="diff-line" data-line-old="11" data-line-new="11"><div class="diff-cell-num">11</div><div class="diff-cell-content">second line</div></div>
                <div class="diff-line" data-line-old="12"><div class="diff-cell-num">12</div><div class="diff-cell-content">left-only line</div></div>
                <div class="diff-line" data-line-new="13"><div class="diff-cell-num">13</div><div class="diff-cell-content">right-only line</div></div>
            </div>
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
        // No `.diff-line[data-line-new="12"]` exists — that row is left-only.
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

    it('skips rows that lack a .diff-cell-content', () => {
        // Defensive: `row.querySelector('.diff-cell-content')?.textContent`
        // returns undefined when the row has no content cell, and the
        // `if (content !== undefined)` check skips it.
        document.body.innerHTML = `
            <div id="empty-row-root">
                <div class="diff-line" data-line-old="1"><div class="diff-cell-num">num</div><div class="diff-cell-content">has cells</div></div>
                <div class="diff-line" data-line-old="2"></div>
                <div class="diff-line" data-line-old="3"><div class="diff-cell-num">num</div><div class="diff-cell-content">also has cells</div></div>
            </div>
        `;
        const emptyRoot = document.getElementById('empty-row-root');
        expect(extractLineSnippet({ root: emptyRoot, side: 'left', startLine: 1, endLine: 3 }))
            .toBe('has cells\nalso has cells');
    });

    it('trimEnd strips trailing whitespace from the joined snippet only at the end', () => {
        document.body.innerHTML = `
            <div id="ws-root">
                <div class="diff-line" data-line-old="1"><div class="diff-cell-num">num</div><div class="diff-cell-content">has   interior   spaces</div></div>
                <div class="diff-line" data-line-old="2"><div class="diff-cell-num">num</div><div class="diff-cell-content">trailing on last line   </div></div>
            </div>
        `;
        const wsRoot = document.getElementById('ws-root');
        const result = extractLineSnippet({ root: wsRoot, side: 'left', startLine: 1, endLine: 2 });
        // Interior whitespace preserved; trailing whitespace on the last line trimmed.
        expect(result).toBe('has   interior   spaces\ntrailing on last line');
    });
});

describe('line comment anchors', () => {
    it('creates only left and right line points', () => {
        expect(createLinePoint(12, 'left')).toEqual({ line: 12, side: 'left' });
        expect(createLinePoint(12, 'right')).toEqual({ line: 12, side: 'right' });
        expect(createLinePoint(null, 'right')).toBeNull();
        expect(createLinePoint(12, 'file')).toBeNull();
    });

    it('compares line points by side and line number', () => {
        expect(areLinePointsEqual(
            { line: 12, side: 'right' },
            { line: 12, side: 'right' },
        )).toBe(true);

        expect(areLinePointsEqual(
            { line: 12, side: 'right' },
            { line: 12, side: 'left' },
        )).toBe(false);
    });

    it('matches context rows with side-specific old and new coordinates', () => {
        const contextOld191New192 = { rowSide: 'context', oldLine: 191, newLine: 192 };

        expect(rowContainsLinePoint(
            contextOld191New192.rowSide,
            contextOld191New192.oldLine,
            contextOld191New192.newLine,
            { side: 'right', line: 192 },
        )).toBe(true);

        expect(rowContainsLinePoint(
            contextOld191New192.rowSide,
            contextOld191New192.oldLine,
            contextOld191New192.newLine,
            { side: 'left', line: 192 },
        )).toBe(false);

        expect(rowContainsLinePoint(
            contextOld191New192.rowSide,
            contextOld191New192.oldLine,
            contextOld191New192.newLine,
            { side: 'left', line: 191 },
        )).toBe(true);

        expect(rowContainsLinePoint(
            'left',
            192,
            null,
            { side: 'right', line: 192 },
        )).toBe(false);
    });

    it('renders the form only on the row containing the selected side coordinate', () => {
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };

        const component = createDiffFile({
            fileId: 'file-1',
            filePath: 'app/Service.php',
            isReviewed: false,
        });

        component.setLineSelection({ side: 'right', line: 192 });
        component.showForm = true;

        expect(component.shouldShowLineCommentForm('context', 191, 192)).toBe(true);
        expect(component.shouldShowLineCommentForm('left', 192, null)).toBe(false);
        expect(component.isRowInSelection('context', 191, 192)).toBe(true);
        expect(component.isRowInSelection('left', 192, null)).toBe(false);

        delete globalThis.Alpine;
    });
});

describe('install', () => {
    afterEach(() => {
        delete window.Alpine;
    });

    it('registers the latest diffFile Alpine factory every time it loads', () => {
        const data = vi.fn();
        window.Alpine = { data };

        expect(install(window)).toBe(true);
        expect(data).toHaveBeenCalledTimes(1);
        expect(data).toHaveBeenCalledWith('diffFile', expect.any(Function));

        expect(install(window)).toBe(true);
        expect(data).toHaveBeenCalledTimes(2);
    });

    it('returns false when Alpine is not present', () => {
        expect(install(window)).toBe(false);
    });

    it('returns false before Alpine loads and registers once Alpine is available', () => {
        expect(install(window)).toBe(false);

        const data = vi.fn();
        window.Alpine = { data };
        expect(install(window)).toBe(true);
        expect(data).toHaveBeenCalledTimes(1);
    });
});

describe('diff action diagnostics', () => {
    afterEach(() => {
        delete globalThis.Alpine;
        delete window.__rfaPendingExpandFiles;
    });

    it('dispatches a start event for measured diff actions', () => {
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };

        const component = createDiffFile({
            fileId: 'file-1',
            filePath: 'resources/views/pages/review-page.blade.php',
            isReviewed: false,
        });

        component.$dispatch = vi.fn();
        component.markDiffActionStart('expandContext');

        expect(component.$dispatch).toHaveBeenCalledWith('rfa:diff-action-start', {
            fileId: 'file-1',
            action: 'expandContext',
        });
    });
});

describe('diff file lifecycle', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
    });

    afterEach(() => {
        vi.useRealTimers();
        delete globalThis.Alpine;
    });

    it('clears the escape hint timer on destroy', () => {
        const component = createDiffFile({
            fileId: 'file-1',
            filePath: 'resources/views/pages/review-page.blade.php',
            isReviewed: false,
        });

        component.formBody = 'draft comment';
        component.handleEscape();

        expect(component.escHint).toBe(true);
        expect(component.escTimer).not.toBeNull();

        component.destroy();

        expect(component.escTimer).toBeNull();
        expect(component.escHint).toBe(false);

        vi.advanceTimersByTime(1500);

        expect(component.escHint).toBe(false);
    });
});

describe('expand focus restoration', () => {
    let root;

    beforeEach(() => {
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
        root = document.createElement('div');
        document.body.appendChild(root);
    });

    afterEach(() => {
        root.remove();
        delete globalThis.Alpine;
        delete window.__rfaPendingExpandFiles;
    });

    function makeComponent() {
        const component = createDiffFile({
            fileId: 'file-1',
            filePath: 'resources/views/pages/review-page.blade.php',
            isReviewed: false,
        });
        component.$root = root;
        component.$nextTick = (fn) => fn();
        return component;
    }

    describe('expanderToRefocus', () => {
        it('returns the first expander still sitting at the gap', () => {
            root.innerHTML = '<button data-expand-gap="1">15</button><button data-expand-gap="1">7 hidden lines</button>';
            expect(expanderToRefocus(root, 1)).toBe(root.querySelector('button'));
        });

        it('returns null when the gap fully closed (no expander left there)', () => {
            root.innerHTML = '<button data-expand-gap="2">x</button>';
            expect(expanderToRefocus(root, 1)).toBeNull();
        });

        it.each([[null], [undefined], ['']])('returns null for a missing gap key (%s)', (key) => {
            root.innerHTML = '<button data-expand-gap="1">x</button>';
            expect(expanderToRefocus(root, key)).toBeNull();
        });

        it('treats gap index 0 as a real key', () => {
            root.innerHTML = '<button data-expand-gap="0">x</button>';
            expect(expanderToRefocus(root, 0)).toBe(root.querySelector('button'));
        });

        it('returns null without a root', () => {
            expect(expanderToRefocus(null, 1)).toBeNull();
        });
    });

    describe('armExpandRefocus', () => {
        it('arms on keyboard activation (click detail 0) with a gap key', () => {
            const component = makeComponent();
            component.armExpandRefocus({ detail: 0 }, 1);
            expect(component._refocusExpandKey).toBe(1);
        });

        it('does not arm on mouse activation (click detail >= 1)', () => {
            const component = makeComponent();
            component.armExpandRefocus({ detail: 1 }, 1);
            expect(component._refocusExpandKey).toBeNull();
        });

        it('does not arm without a gap key (master full-file expander)', () => {
            const component = makeComponent();
            component.armExpandRefocus({ detail: 0 }, null);
            expect(component._refocusExpandKey).toBeNull();
        });
    });

    describe('restore on completion', () => {
        it('returns focus to the remaining expander after a keyboard gap expand', () => {
            const component = makeComponent();
            component.init();
            component.armExpandRefocus({ detail: 0 }, 1);

            // Simulate the post-expand morph leaving a smaller gap at hunk index 1.
            root.innerHTML = '<button data-expand-gap="1">7 hidden lines</button>';
            window.dispatchEvent(new window.CustomEvent('rfa:diff-action-completed', {
                detail: { fileId: 'file-1', action: 'expandGap' },
            }));

            expect(document.activeElement).toBe(root.querySelector('[data-expand-gap="1"]'));
            expect(component._refocusExpandKey).toBeNull();

            component.destroy();
        });

        it('ignores completion events for other files', () => {
            const component = makeComponent();
            component.init();
            component.armExpandRefocus({ detail: 0 }, 1);

            root.innerHTML = '<button data-expand-gap="1">7 hidden lines</button>';
            window.dispatchEvent(new window.CustomEvent('rfa:diff-action-completed', {
                detail: { fileId: 'other-file', action: 'expandGap' },
            }));

            expect(document.activeElement).not.toBe(root.querySelector('[data-expand-gap="1"]'));
            expect(component._refocusExpandKey).toBe(1);

            component.destroy();
        });

        it('stops restoring focus after destroy', () => {
            const component = makeComponent();
            component.init();
            component.destroy();
            component.armExpandRefocus({ detail: 0 }, 1);

            root.innerHTML = '<button data-expand-gap="1">7 hidden lines</button>';
            window.dispatchEvent(new window.CustomEvent('rfa:diff-action-completed', {
                detail: { fileId: 'file-1', action: 'expandGap' },
            }));

            expect(document.activeElement).not.toBe(root.querySelector('[data-expand-gap="1"]'));

            component.destroy();
        });
    });
});

describe('pending comment form persistence', () => {
    beforeEach(() => {
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
    });

    afterEach(() => {
        delete globalThis.Alpine;
    });

    function makeComponent(fileId) {
        const component = createDiffFile({
            fileId,
            filePath: 'app/Foo.php',
            isReviewed: false,
        });
        component.$dispatch = () => {};
        return component;
    }

    it('restores an unsent draft after unmount and remount', () => {
        const first = makeComponent('persist-1');
        first.showForm = true;
        first.formBody = 'work in progress';
        first.formSide = 'right';
        first.formLine = 12;
        first.formEndLine = 14;
        first.formStartPoint = { line: 12, side: 'right' };
        first.formEndPoint = { line: 14, side: 'right' };
        first.destroy();

        const second = makeComponent('persist-1');
        second.collapsed = true;
        second.init();
        second.destroy();

        expect(second.showForm).toBe(true);
        expect(second.formBody).toBe('work in progress');
        expect(second.formSide).toBe('right');
        expect(second.formLine).toBe(12);
        expect(second.formEndLine).toBe(14);
        expect(second.formStartPoint).toEqual({ line: 12, side: 'right' });
        expect(second.formEndPoint).toEqual({ line: 14, side: 'right' });
        expect(second.collapsed).toBe(false);
    });

    it('does not persist a closed or empty form', () => {
        const closed = makeComponent('persist-2');
        closed.formBody = 'typed then closed';
        closed.showForm = false;
        closed.destroy();

        const blank = makeComponent('persist-3');
        blank.showForm = true;
        blank.formBody = '   ';
        blank.destroy();

        const remountClosed = makeComponent('persist-2');
        remountClosed.init();
        remountClosed.destroy();
        const remountBlank = makeComponent('persist-3');
        remountBlank.init();
        remountBlank.destroy();

        expect(remountClosed.showForm).toBe(false);
        expect(remountClosed.formBody).toBe('');
        expect(remountBlank.showForm).toBe(false);
    });

    it('consumes the snapshot on restore so it does not resurrect twice', () => {
        const first = makeComponent('persist-4');
        first.showForm = true;
        first.formBody = 'only once';
        first.destroy();

        const second = makeComponent('persist-4');
        second.init();
        second.cancelForm();
        second.destroy();

        const third = makeComponent('persist-4');
        third.init();
        third.destroy();

        expect(third.showForm).toBe(false);
        expect(third.formBody).toBe('');
    });
});

describe('pending comment form cleared on SPA navigation', () => {
    beforeEach(() => {
        // autoInstall needs Alpine present so install() runs and the
        // livewire:navigating cleanup hook is registered.
        globalThis.Alpine = { store: () => ({ collapseAll: false }), data: () => {} };
        window.Alpine = globalThis.Alpine;
    });

    afterEach(() => {
        delete globalThis.Alpine;
        delete window.Alpine;
        delete window.__diffFilePendingFormsCleanup;
    });

    function makeComponent(fileId) {
        const component = createDiffFile({ fileId, filePath: 'app/Foo.php', isReviewed: false });
        component.$dispatch = () => {};
        return component;
    }

    it('drops an unsent draft when the page navigates away (so it cannot resurrect on a same-id file elsewhere)', () => {
        // Registers the real production livewire:navigating cleanup hook.
        autoInstall(window);

        const first = makeComponent('nav-collide');
        first.showForm = true;
        first.formBody = 'unsent, must not cross the navigation boundary';
        first.destroy(); // snapshots the draft into the page-lifetime Map

        document.dispatchEvent(new window.Event('livewire:navigating'));

        // A different page mounts a file whose content-hash id collides.
        const second = makeComponent('nav-collide');
        second.init();
        second.destroy();

        expect(second.showForm).toBe(false);
        expect(second.formBody).toBe('');
    });
});

describe('formatCitation', () => {
    it('quotes a single line and appends a blank line for the cursor', () => {
        expect(formatCitation('Identify the single most undeniable paper cut'))
            .toBe('> Identify the single most undeniable paper cut\n\n');
    });

    it('prefixes every line of a multi-line selection', () => {
        expect(formatCitation('first line\nsecond line\nthird line'))
            .toBe('> first line\n> second line\n> third line\n\n');
    });

    it('drops blank lines at the edges but keeps each line\'s own indentation', () => {
        expect(formatCitation('\n  spaced selection  \n')).toBe('>   spaced selection  \n\n');
    });

    it('preserves the indentation of quoted code (does not dedent the first line)', () => {
        expect(formatCitation('    if (foo) {\n        bar();\n    }'))
            .toBe('>     if (foo) {\n>         bar();\n>     }\n\n');
    });

    it('still quotes interior blank lines', () => {
        expect(formatCitation('para one\n\npara two')).toBe('> para one\n> \n> para two\n\n');
    });

    it.each([
        ['', ''],
        ['   ', ''],
        ['\n\n', ''],
    ])('returns empty string for empty/whitespace input (%j)', (input, expected) => {
        expect(formatCitation(input)).toBe(expected);
    });

    it.each([[null], [undefined], [42]])('returns empty string for non-string input (%j)', (input) => {
        expect(formatCitation(input)).toBe('');
    });
});

describe('selectionSourceText', () => {
    let root;

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // Builds a Range from the first occurrence of `startStr` in the start cell's
    // text to the end of the first occurrence of `endStr` in the end cell's text.
    function rangeFromText(startSelector, startStr, endSelector, endStr) {
        const startNode = document.querySelector(startSelector).firstChild;
        const endNode = document.querySelector(endSelector).firstChild;
        const range = document.createRange();
        range.setStart(startNode, startNode.textContent.indexOf(startStr));
        range.setEnd(endNode, endNode.textContent.indexOf(endStr) + endStr.length);
        return range;
    }

    it('excludes the line-number and +/- prefix gutters when a selection crosses a row', () => {
        // The reported bug: selecting "Otwell … Porzio)" across a hard-wrapped
        // markdown paragraph dragged the line-number (6) and add-marker (+)
        // gutter cells of the second row into the citation.
        document.body.innerHTML = `
            <div id="file-root">
                <div class="diff-line" data-line-new="5"><div class="diff-cell-num"></div><div class="diff-cell-num">5</div><div class="diff-cell-prefix">+</div><div class="diff-cell-content">standards and the simplification panel (Otwell, DHH, Wathan,</div></div>
                <div class="diff-line" data-line-new="6"><div class="diff-cell-num"></div><div class="diff-cell-num">6</div><div class="diff-cell-prefix">+</div><div class="diff-cell-content">   Porzio), merged into one prioritized list.</div></div>
            </div>
        `;
        root = document.getElementById('file-root');
        const range = rangeFromText(
            '[data-line-new="5"] .diff-cell-content', 'Otwell',
            '[data-line-new="6"] .diff-cell-content', 'Porzio)',
        );

        const text = selectionSourceText(range, root);

        // Real source only — no `5`, `6`, or `+` chrome — with each row's own
        // line break and indentation preserved.
        expect(text).toBe('Otwell, DHH, Wathan,\n   Porzio)');
        expect(formatCitation(text)).toBe('> Otwell, DHH, Wathan,\n>    Porzio)\n\n');
    });

    it('returns the clamped substring for a selection within a single line', () => {
        document.body.innerHTML = `
            <div id="file-root">
                <div class="diff-line" data-line-new="9"><div class="diff-cell-num">9</div><div class="diff-cell-prefix"> </div><div class="diff-cell-content">    const value = compute(input);</div></div>
            </div>
        `;
        root = document.getElementById('file-root');
        const range = rangeFromText(
            '[data-line-new="9"] .diff-cell-content', 'compute',
            '[data-line-new="9"] .diff-cell-content', 'compute',
        );

        expect(selectionSourceText(range, root)).toBe('compute');
    });

    it('preserves the leading indentation of a whole-line selection', () => {
        document.body.innerHTML = `
            <div id="file-root">
                <div class="diff-line" data-line-new="9"><div class="diff-cell-num">9</div><div class="diff-cell-prefix">+</div><div class="diff-cell-content">    indented();</div></div>
            </div>
        `;
        root = document.getElementById('file-root');
        const cell = document.querySelector('.diff-cell-content');
        const range = document.createRange();
        range.selectNodeContents(cell);

        expect(selectionSourceText(range, root)).toBe('    indented();');
    });

    it('quotes a split-view context row once, not once per mirror cell', () => {
        document.body.innerHTML = `
            <div id="file-root">
                <div class="diff-line" data-line-old="3" data-line-new="3"><div class="diff-cell-num">3</div><div class="diff-cell-content">shared context</div><div class="diff-cell-content diff-cell-content-mirror" aria-hidden="true">shared context</div></div>
            </div>
        `;
        root = document.getElementById('file-root');
        const cell = document.querySelector('.diff-cell-content:not(.diff-cell-content-mirror)');
        const range = document.createRange();
        range.selectNodeContents(cell);

        expect(selectionSourceText(range, root)).toBe('shared context');
    });

    it('returns an empty string when the range or root is missing', () => {
        expect(selectionSourceText(null, document.body)).toBe('');
        expect(selectionSourceText(document.createRange(), null)).toBe('');
    });
});

describe('closestDiffLine', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('resolves a text node up to its .diff-line row', () => {
        document.body.innerHTML = '<div class="diff-line" data-line-new="5"><div class="diff-cell-content">hello</div></div>';
        const textNode = document.querySelector('.diff-cell-content').firstChild;
        expect(closestDiffLine(textNode)).toBe(document.querySelector('.diff-line'));
    });

    it('resolves an element node to its .diff-line row', () => {
        document.body.innerHTML = '<div class="diff-line" data-line-new="5"><div class="diff-cell-content">hello</div></div>';
        const cell = document.querySelector('.diff-cell-content');
        expect(closestDiffLine(cell)).toBe(document.querySelector('.diff-line'));
    });

    it('returns null when the node is not inside a diff row', () => {
        document.body.innerHTML = '<div class="comment-box">a comment</div>';
        expect(closestDiffLine(document.querySelector('.comment-box'))).toBeNull();
    });

    it('returns null for nullish input', () => {
        expect(closestDiffLine(null)).toBeNull();
    });
});

describe('selectionLineRange', () => {
    let root;

    beforeEach(() => {
        document.body.innerHTML = `
            <div id="file-root">
                <div class="diff-line" data-line-old="10" data-line-new="10"><div class="diff-cell-content">ctx line</div></div>
                <div class="diff-line" data-line-new="11"><div class="diff-cell-content">added line</div></div>
                <div class="diff-line" data-line-old="12"><div class="diff-cell-content">removed line</div></div>
            </div>
            <div id="other-root">
                <div class="diff-line" data-line-new="99"><div class="diff-cell-content">elsewhere</div></div>
            </div>
        `;
        root = document.getElementById('file-root');
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    function rangeOver(startSelector, endSelector = startSelector) {
        const range = document.createRange();
        range.setStart(document.querySelector(startSelector).firstChild, 0);
        const endNode = document.querySelector(endSelector).firstChild;
        range.setEnd(endNode, endNode.length);
        return range;
    }

    it('anchors a single added line to the right side', () => {
        const range = rangeOver('[data-line-new="11"] .diff-cell-content');
        expect(selectionLineRange(range, root)).toEqual({ side: 'right', startLine: 11, endLine: 11 });
    });

    it('prefers the new (right) side on a context row that carries both', () => {
        const range = rangeOver('[data-line-new="10"] .diff-cell-content');
        expect(selectionLineRange(range, root)).toEqual({ side: 'right', startLine: 10, endLine: 10 });
    });

    it('uses the old (left) side for a removed-only row', () => {
        const range = rangeOver('[data-line-old="12"] .diff-cell-content');
        expect(selectionLineRange(range, root)).toEqual({ side: 'left', startLine: 12, endLine: 12 });
    });

    it('spans the start row to the end row on the anchored side', () => {
        const range = rangeOver('[data-line-new="10"] .diff-cell-content', '[data-line-new="11"] .diff-cell-content');
        expect(selectionLineRange(range, root)).toEqual({ side: 'right', startLine: 10, endLine: 11 });
    });

    it('falls back to the start line when the end row lacks the anchored side', () => {
        // Start on the right side (line 11) but end on a left-only row: endLine stays 11.
        const range = rangeOver('[data-line-new="11"] .diff-cell-content', '[data-line-old="12"] .diff-cell-content');
        expect(selectionLineRange(range, root)).toEqual({ side: 'right', startLine: 11, endLine: 11 });
    });

    it('returns null when the selection starts outside the given root', () => {
        const range = rangeOver('#other-root [data-line-new="99"] .diff-cell-content');
        expect(selectionLineRange(range, root)).toBeNull();
    });

    it('returns null for a missing range', () => {
        expect(selectionLineRange(null, root)).toBeNull();
    });
});

describe('selectionLineRange in split view', () => {
    let root;

    beforeEach(() => {
        document.body.innerHTML = `
            <div id="file-root" data-file-id="f1">
                <div class="diff-grid" data-view-mode="split">
                    <div class="diff-line" data-type="context" data-line-old="10" data-line-new="20">
                        <div class="diff-cell diff-cell-content">context text</div>
                        <div class="diff-cell diff-cell-content diff-cell-content-mirror">context text</div>
                    </div>
                </div>
            </div>`;
        root = document.getElementById('file-root');
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    function rangeIn(selector) {
        const range = document.createRange();
        const node = document.querySelector(selector).firstChild;
        range.setStart(node, 0);
        range.setEnd(node, node.length);
        return range;
    }

    it('anchors a selection in the left content cell to the old side', () => {
        // The primary `.diff-cell-content` is the original (left) side in split view.
        expect(selectionLineRange(rangeIn('.diff-cell-content'), root))
            .toEqual({ side: 'left', startLine: 10, endLine: 10 });
    });

    it('anchors a selection in the right mirror cell to the new side', () => {
        expect(selectionLineRange(rangeIn('.diff-cell-content-mirror'), root))
            .toEqual({ side: 'right', startLine: 20, endLine: 20 });
    });

    it('keeps the new-side default for a context row in unified view', () => {
        root.querySelector('.diff-grid').dataset.viewMode = 'unified';
        expect(selectionLineRange(rangeIn('.diff-cell-content'), root))
            .toEqual({ side: 'right', startLine: 20, endLine: 20 });
    });
});

describe('comment on text selection', () => {
    let root;

    beforeEach(() => {
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
        document.body.innerHTML = `
            <div id="file-root">
                <div class="diff-line" data-line-new="13"><div class="diff-cell-num"></div><div class="diff-cell-num">13</div><div class="diff-cell-prefix">+</div><div class="diff-cell-content">Identify the single most undeniable paper cut</div></div>
                <div class="diff-line" data-line-new="14"><div class="diff-cell-num"></div><div class="diff-cell-num">14</div><div class="diff-cell-prefix">+</div><div class="diff-cell-content">more context</div></div>
            </div>
        `;
        root = document.getElementById('file-root');
    });

    afterEach(() => {
        document.body.innerHTML = '';
        delete globalThis.Alpine;
        vi.restoreAllMocks();
    });

    function makeComponent() {
        const component = createDiffFile({ fileId: 'file-1', filePath: 'README.md', isReviewed: false });
        component.$el = root;
        component.$dispatch = vi.fn();
        component.$nextTick = (fn) => fn();
        component.$refs = { commentInput: { focus: vi.fn(), value: '', setSelectionRange: vi.fn() } };
        return component;
    }

    function stubSelection({ text, startSelector, endSelector = startSelector, collapsed = false }) {
        const range = document.createRange();
        const startNode = document.querySelector(startSelector).firstChild;
        const endNode = document.querySelector(endSelector).firstChild;
        range.setStart(startNode, 0);
        range.setEnd(endNode, endNode.length);
        vi.spyOn(window, 'getSelection').mockReturnValue({
            rangeCount: 1,
            isCollapsed: collapsed,
            anchorNode: startNode,
            getRangeAt: () => range,
            toString: () => text,
        });
        return range;
    }

    it('opens the form on the selected line seeded with a citation', () => {
        const component = makeComponent();
        stubSelection({
            text: 'Identify the single most undeniable paper cut',
            startSelector: '[data-line-new="13"] .diff-cell-content',
        });

        component.commentOnSelection();

        expect(component.showForm).toBe(true);
        expect(component.formSide).toBe('right');
        expect(component.formLine).toBe(13);
        expect(component.formEndLine).toBe(13);
        expect(component.formBody).toBe('> Identify the single most undeniable paper cut\n\n');
        expect(component.$refs.commentInput.focus).toHaveBeenCalled();
    });

    it('anchors a multi-line selection across the spanned rows and excludes the gutter chrome', () => {
        const component = makeComponent();
        stubSelection({
            text: 'ignored — citation reads source text from the DOM, not toString()',
            startSelector: '[data-line-new="13"] .diff-cell-content',
            endSelector: '[data-line-new="14"] .diff-cell-content',
        });

        component.commentOnSelection();

        expect(component.formLine).toBe(13);
        expect(component.formEndLine).toBe(14);
        // The line-number (13/14) and `+` prefix cells the range spans never
        // leak into the quote — each row contributes only its source line.
        expect(component.formBody).toBe('> Identify the single most undeniable paper cut\n> more context\n\n');
    });

    it('does nothing when the selection is collapsed', () => {
        const component = makeComponent();
        stubSelection({ text: '', startSelector: '[data-line-new="13"] .diff-cell-content', collapsed: true });

        component.commentOnSelection();

        expect(component.showForm).toBe(false);
        expect(component.formBody).toBe('');
    });

    it('does not overwrite text already in the composer', () => {
        const component = makeComponent();
        component.formBody = 'half-written thought';
        stubSelection({
            text: 'Identify the single most undeniable paper cut',
            startSelector: '[data-line-new="13"] .diff-cell-content',
        });

        component.commentOnSelection();

        expect(component.formBody).toBe('half-written thought');
        expect(component.formLine).toBe(13);
    });
});

describe('citation pre-fill on the drag path', () => {
    beforeEach(() => {
        globalThis.Alpine = { store: () => ({ collapseAll: false }) };
    });

    afterEach(() => {
        delete globalThis.Alpine;
    });

    function makeComponent() {
        const component = createDiffFile({ fileId: 'file-1', filePath: 'README.md', isReviewed: false });
        component.$dispatch = vi.fn();
        component.$nextTick = (fn) => fn();
        component.$refs = { commentInput: { focus: vi.fn(), value: '', setSelectionRange: vi.fn() } };
        return component;
    }

    it('applies the pending citation when the line drag ends', () => {
        const component = makeComponent();
        component.isDragging = true;
        component.setLineSelection({ line: 13, side: 'right' });
        component._pendingCitation = '> cited line\n\n';

        component.endDrag();

        expect(component.showForm).toBe(true);
        expect(component.formBody).toBe('> cited line\n\n');
        expect(component._pendingCitation).toBe('');
    });

    it('does not overwrite an existing draft when the drag ends', () => {
        const component = makeComponent();
        component.isDragging = true;
        component.formBody = 'existing draft';
        component._pendingCitation = '> cited line\n\n';

        component.endDrag();

        expect(component.formBody).toBe('existing draft');
        expect(component._pendingCitation).toBe('');
    });

    it('clears any pending citation on cancel', () => {
        const component = makeComponent();
        component._pendingCitation = '> cited line\n\n';

        component.cancelForm();

        expect(component._pendingCitation).toBe('');
        expect(component.formBody).toBe('');
    });
});

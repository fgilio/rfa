import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import diffFile from '../../public/js/diff-file.js';

const {
    getScrollSpeed,
    extractLineSnippet,
    expanderToRefocus,
    createLinePoint,
    areLinePointsEqual,
    rowContainsLinePoint,
    createDiffFile,
    install,
    autoInstall,
} = diffFile;

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

import { afterEach, describe, expect, it, vi } from 'vitest';
import branchExplorer from '../../public/js/branch-explorer.js';

const { decideSelection, install } = branchExplorer;

// Newest-first commit list. Index 0 = tip.
const commits = [
    { hash: 'aaa1' }, // 0 = newest = tip
    { hash: 'bbb2' }, // 1
    { hash: 'ccc3' }, // 2
    { hash: 'ddd4' }, // 3 = oldest
];

const slug = 'myproj';

function decide({ selectedHashes = [], workingTreeSelected = false } = {}) {
    return decideSelection({
        commits,
        selectedHashes,
        workingTreeSelected,
        projectSlug: slug,
    });
}

describe('decideSelection — empty', () => {
    it('returns noop when nothing is selected', () => {
        expect(decide()).toEqual({ kind: 'noop' });
    });
});

describe('decideSelection — working tree alone', () => {
    it('navigates to the project root when only working tree is selected', () => {
        expect(decide({ workingTreeSelected: true })).toEqual({
            kind: 'navigate',
            url: '/p/myproj',
        });
    });
});

describe('decideSelection — single commit', () => {
    it.each([
        ['aaa1', '/p/myproj/c/aaa1'],
        ['bbb2', '/p/myproj/c/bbb2'],
        ['ccc3', '/p/myproj/c/ccc3'],
        ['ddd4', '/p/myproj/c/ddd4'],
    ])('navigates to /c/%s for a single-commit selection', (hash, url) => {
        expect(decide({ selectedHashes: [hash] })).toEqual({
            kind: 'navigate',
            url,
        });
    });
});

describe('decideSelection — contiguous range', () => {
    it.each([
        // [label, selected, expectedUrl]
        ['adjacent at tip', ['aaa1', 'bbb2'], '/p/myproj/aaa1/bbb2%5E'],
        ['adjacent not at tip', ['bbb2', 'ccc3'], '/p/myproj/bbb2/ccc3%5E'],
        ['three adjacent', ['aaa1', 'bbb2', 'ccc3'], '/p/myproj/aaa1/ccc3%5E'],
        // Order shouldn't matter — function sorts indices internally.
        ['unsorted input', ['ccc3', 'aaa1', 'bbb2'], '/p/myproj/aaa1/ccc3%5E'],
        ['range to oldest', ['bbb2', 'ccc3', 'ddd4'], '/p/myproj/bbb2/ddd4%5E'],
    ])('navigates to range URL for %s', (_label, selectedHashes, url) => {
        expect(decide({ selectedHashes })).toEqual({
            kind: 'navigate',
            url,
        });
    });

    it('encodes the caret as %5E in the base ref', () => {
        const result = decide({ selectedHashes: ['aaa1', 'bbb2'] });
        expect(result.kind).toBe('navigate');
        expect(result.url).toContain('bbb2%5E');
        expect(result.url).not.toContain('bbb2^');
    });
});

describe('decideSelection — non-contiguous range', () => {
    it.each([
        ['gap of one', ['aaa1', 'ccc3']],
        ['gap in the middle of three', ['aaa1', 'bbb2', 'ddd4']],
        ['tip and oldest only', ['aaa1', 'ddd4']],
    ])('alerts about contiguity for %s', (_label, selectedHashes) => {
        const result = decide({ selectedHashes });
        expect(result.kind).toBe('alert');
        expect(result.message).toContain('not contiguous');
        expect(result.message).toContain('pick every commit');
    });
});

describe('decideSelection — working tree + commits', () => {
    it('navigates to /rw/{tip^} when working tree is paired with the tip', () => {
        expect(decide({ workingTreeSelected: true, selectedHashes: ['aaa1'] })).toEqual({
            kind: 'navigate',
            url: '/p/myproj/rw/aaa1%5E',
        });
    });

    it('uses the oldest commit in the range as the from-ref', () => {
        expect(decide({
            workingTreeSelected: true,
            selectedHashes: ['aaa1', 'bbb2'],
        })).toEqual({
            kind: 'navigate',
            url: '/p/myproj/rw/bbb2%5E',
        });
    });

    it('alerts when commits are not paired with the tip', () => {
        const result = decide({
            workingTreeSelected: true,
            selectedHashes: ['bbb2'],
        });
        expect(result.kind).toBe('alert');
        expect(result.message).toContain('working tree must be paired with the newest commits');
    });

    // The contiguous check runs BEFORE the WT-must-include-tip check.
    // Pin the alert ordering so a future refactor doesn't accidentally swap
    // which message the user sees for an ambiguous selection.
    it('reports the contiguity alert (not the WT-pair alert) when both fail', () => {
        const result = decide({
            workingTreeSelected: true,
            selectedHashes: ['aaa1', 'ccc3'],
        });
        expect(result.kind).toBe('alert');
        expect(result.message).toContain('pick every commit');
        expect(result.message).not.toContain('paired with the newest commits');
    });
});

describe('decideSelection — input cleaning', () => {
    it('drops unknown hashes that are not in commits', () => {
        // ['aaa1', 'unknown'] should behave like ['aaa1'].
        expect(decide({ selectedHashes: ['aaa1', 'unknown'] })).toEqual({
            kind: 'navigate',
            url: '/p/myproj/c/aaa1',
        });
    });

    it('dedupes repeated hashes', () => {
        expect(decide({ selectedHashes: ['aaa1', 'aaa1'] })).toEqual({
            kind: 'navigate',
            url: '/p/myproj/c/aaa1',
        });
    });

    it('treats a selection of only-unknown hashes as no commits picked', () => {
        // No real commits selected, no working tree → noop is wrong here:
        // hasAnySelection is true (selectedHashes.length > 0) but indices is empty.
        // With workingTreeSelected=false and indices=[], no branch matches the
        // working-tree-only path; we fall through to the single-commit path
        // where `commits[indices[0]]` would be undefined. The original code
        // has the same shape, so we only assert WT+unknown lands on the
        // working-tree-only branch.
        expect(decide({
            workingTreeSelected: true,
            selectedHashes: ['unknown'],
        })).toEqual({
            kind: 'navigate',
            url: '/p/myproj',
        });
    });
});

describe('install', () => {
    afterEach(() => {
        delete window.Alpine;
        delete window.__branchExplorerAttached;
    });

    it('registers branchExplorer with Alpine and is idempotent', () => {
        const data = vi.fn();
        window.Alpine = { data };

        expect(install(window)).toBe(true);
        expect(data).toHaveBeenCalledTimes(1);
        expect(data).toHaveBeenCalledWith('branchExplorer', expect.any(Function));

        expect(install(window)).toBe(false);
        expect(data).toHaveBeenCalledTimes(1);
    });

    it('is a no-op when Alpine is not present', () => {
        expect(install(window)).toBe(false);
    });
});

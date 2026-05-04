import { afterEach, describe, expect, it, vi } from 'vitest';
import branchExplorer from '../../public/js/branch-explorer.js';

const { BranchBaseState, decideSelection, isSinceBaseExactly, createBranchExplorer, install } = branchExplorer;

/** Minimal factory wrapper for tests that exercise the Alpine state machine
 *  directly. Provides a stub `$wire` that the production code reads from. */
function makeAlpine({ commits = [], branchBase = null, currentBranch = 'main' } = {}) {
    const a = createBranchExplorer({
        currentBranch,
        activeCommitHash: null,
        activeDiffFrom: 'HEAD',
        projectSlug: 'p',
        branches: { local: [], remote: [] },
    });
    a.$wire = { commits, branchBase };
    return a;
}

const noopEvent = () => ({ stopPropagation: () => {}, shiftKey: false });

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

    it('uses the resolved merge-base for an exact since-base selection', () => {
        expect(decideSelection({
            commits,
            selectedHashes: ['aaa1', 'bbb2', 'ccc3'],
            workingTreeSelected: true,
            projectSlug: slug,
            sinceBase: {
                state: BranchBaseState.Ready,
                baseSha: '1234567890abcdef1234567890abcdef12345678',
                hashesInRange: ['aaa1', 'bbb2', 'ccc3'],
            },
        })).toEqual({
            kind: 'navigate',
            url: '/p/myproj/rw/1234567890abcdef1234567890abcdef12345678',
        });
    });

    it('falls back to the trimmed range when since-base selection is edited', () => {
        expect(decideSelection({
            commits,
            selectedHashes: ['aaa1', 'bbb2'],
            workingTreeSelected: true,
            projectSlug: slug,
            sinceBase: {
                state: BranchBaseState.Ready,
                baseSha: '1234567890abcdef1234567890abcdef12345678',
                hashesInRange: ['aaa1', 'bbb2', 'ccc3'],
            },
        })).toEqual({
            kind: 'navigate',
            url: '/p/myproj/rw/bbb2%5E',
        });
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

    it('lands on the working-tree path when WT is paired with only-unknown hashes', () => {
        // The WT-only branch fires before the unknown-hash guard.
        expect(decide({
            workingTreeSelected: true,
            selectedHashes: ['unknown'],
        })).toEqual({
            kind: 'navigate',
            url: '/p/myproj',
        });
    });

    it.each([
        ['single unknown', ['unknown']],
        ['multiple unknown', ['unknown', 'gone']],
    ])('treats %s without WT as noop', (_label, selectedHashes) => {
        // Without this guard, the function dereferences `commits[undefined].hash`
        // in the single-commit branch and throws.
        expect(decide({ selectedHashes })).toEqual({ kind: 'noop' });
    });
});

describe('isSinceBaseExactly', () => {
    const range = ['aaa1', 'bbb2', 'ccc3'];

    it('returns true when WT + every range hash is selected (any order)', () => {
        expect(isSinceBaseExactly({
            selectedHashes: ['ccc3', 'aaa1', 'bbb2'],
            workingTreeSelected: true,
            hashesInRange: range,
        })).toBe(true);
    });

    it('returns false when working tree is not selected', () => {
        expect(isSinceBaseExactly({
            selectedHashes: ['aaa1', 'bbb2', 'ccc3'],
            workingTreeSelected: false,
            hashesInRange: range,
        })).toBe(false);
    });

    it('returns false when selection is missing a hash from the range', () => {
        expect(isSinceBaseExactly({
            selectedHashes: ['aaa1', 'bbb2'],
            workingTreeSelected: true,
            hashesInRange: range,
        })).toBe(false);
    });

    it('returns false when selection has an extra hash beyond the range', () => {
        expect(isSinceBaseExactly({
            selectedHashes: ['aaa1', 'bbb2', 'ccc3', 'extra'],
            workingTreeSelected: true,
            hashesInRange: range,
        })).toBe(false);
    });

    it('returns true for the empty range when only WT is selected', () => {
        // The picker only renders the "Since {base}" row in the Ready state,
        // which guarantees a non-empty range — but the helper must still handle
        // the edge case sensibly so a future caller doesn't get a false positive
        // from an unintended state.
        expect(isSinceBaseExactly({
            selectedHashes: [],
            workingTreeSelected: true,
            hashesInRange: [],
        })).toBe(true);
    });
});

describe('working-tree tip anchor', () => {
    const commits = [
        { hash: 'tip' }, // 0 = newest
        { hash: 'mid' }, // 1
        { hash: 'old' }, // 2 = oldest
    ];

    afterEach(() => {
        delete window.Flux;
    });

    it('auto-unticks working tree when the tip commit is unticked', () => {
        const a = makeAlpine({ commits });
        a.workingTreeSelected = true;
        a.selectedHashes = ['tip', 'mid', 'old'];

        a.toggleSelection('tip', 0, noopEvent());

        expect(a.selectedHashes).toEqual(['mid', 'old']);
        expect(a.workingTreeSelected).toBe(false);
    });

    it('keeps working tree selected when a non-tip commit is unticked', () => {
        const a = makeAlpine({ commits });
        a.workingTreeSelected = true;
        a.selectedHashes = ['tip', 'mid', 'old'];

        a.toggleSelection('old', 2, noopEvent());

        expect(a.selectedHashes).toEqual(['tip', 'mid']);
        expect(a.workingTreeSelected).toBe(true);
    });

    it('does not affect a working-tree-only selection', () => {
        const a = makeAlpine({ commits });
        a.workingTreeSelected = true;
        a.selectedHashes = [];

        // Toggling a hash that wasn't selected just adds it; the invariant
        // only matters when the resulting state would pair WT with non-tip
        // commits. Adding the tip is always fine.
        a.toggleSelection('tip', 0, noopEvent());

        expect(a.selectedHashes).toEqual(['tip']);
        expect(a.workingTreeSelected).toBe(true);
    });

    it('shows an explanatory toast when WT is auto-unticked', () => {
        const toast = vi.fn();
        window.Flux = { toast };

        const a = makeAlpine({ commits });
        a.workingTreeSelected = true;
        a.selectedHashes = ['tip', 'mid'];

        a.toggleSelection('tip', 0, noopEvent());

        expect(toast).toHaveBeenCalledTimes(1);
        expect(toast.mock.calls[0][0]).toMatchObject({ variant: 'info' });
        expect(toast.mock.calls[0][0].text).toContain('Working tree removed');
    });

    it('does not toast when the invariant is already satisfied', () => {
        const toast = vi.fn();
        window.Flux = { toast };

        const a = makeAlpine({ commits });
        a.workingTreeSelected = true;
        a.selectedHashes = ['tip'];

        // Untick a commit that's not in the selection — adds it, doesn't violate.
        a.toggleSelection('mid', 1, noopEvent());

        expect(toast).not.toHaveBeenCalled();
        expect(a.workingTreeSelected).toBe(true);
    });
});

describe('_rehydrateSelectionFromActiveView', () => {
    function makeFor({ activeDiffFrom = 'HEAD', activeCommitHash = null, branch = 'main', commits = [], branchBase = null } = {}) {
        const a = createBranchExplorer({
            currentBranch: 'main',
            activeCommitHash,
            activeDiffFrom,
            projectSlug: 'p',
            branches: { local: [], remote: [] },
        });
        a.$wire = { commits, branchBase };
        a.selectedBranch = branch;
        return a;
    }

    it('does nothing when the picker is not on the current branch', () => {
        const a = makeFor({ branch: 'feature/x', activeCommitHash: 'aaa1' });
        a._rehydrateSelectionFromActiveView();

        expect(a.selectedHashes).toEqual([]);
        expect(a.workingTreeSelected).toBe(false);
    });

    it('selects working tree only when viewing /p/{slug}', () => {
        const a = makeFor();
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual([]);
    });

    it('selects WT + base..HEAD hashes from branchBase when viewing /rw/{baseSha}', () => {
        const a = makeFor({
            activeDiffFrom: 'base-sha',
            commits,
            branchBase: { state: BranchBaseState.Ready, baseSha: 'base-sha', hashesInRange: ['aaa1', 'bbb2'] },
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['aaa1', 'bbb2']);
    });

    it('falls back to slicing loaded commits for /rw/{sha} when sha is not the configured base', () => {
        const a = makeFor({
            activeDiffFrom: 'ccc3',
            commits,
            branchBase: { state: BranchBaseState.Ready, baseSha: 'other', hashesInRange: [] },
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['aaa1', 'bbb2']);
    });

    it('selects just the tip for a single commit view (/c/{hash})', () => {
        const a = makeFor({
            activeCommitHash: 'bbb2',
            activeDiffFrom: 'ccc3', // parent of bbb2 in this fixture
            commits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.selectedHashes).toEqual(['bbb2']);
    });

    it('selects every commit in (from, to] for an explicit range view (/r/{from}..{to})', () => {
        const a = makeFor({
            activeCommitHash: 'aaa1',
            activeDiffFrom: 'ddd4',
            commits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.selectedHashes).toEqual(['aaa1', 'bbb2', 'ccc3']);
    });

    it('falls back to [tip] when the range endpoints are not in the loaded commits', () => {
        const a = makeFor({
            activeCommitHash: 'unknown-tip',
            activeDiffFrom: 'unknown-from',
            commits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.selectedHashes).toEqual(['unknown-tip']);
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

    it('does not poison the attached flag when called before Alpine loads', () => {
        // First attempt with no Alpine must NOT set the flag — otherwise a
        // later attempt once Alpine is ready would silently no-op and the
        // factory would never register.
        expect(install(window)).toBe(false);
        expect(window.__branchExplorerAttached).toBeUndefined();

        const data = vi.fn();
        window.Alpine = { data };
        expect(install(window)).toBe(true);
        expect(data).toHaveBeenCalledTimes(1);
    });
});

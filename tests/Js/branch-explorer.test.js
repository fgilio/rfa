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
const shiftEvent = () => ({ stopPropagation: () => {}, shiftKey: true });

// Newest-first commit list. Index 0 = tip.
const commits = [
    { hash: 'aaa1' }, // 0 = newest = tip
    { hash: 'bbb2' }, // 1
    { hash: 'ccc3' }, // 2
    { hash: 'ddd4' }, // 3 = oldest
];

const longCommits = [
    { hash: 'aaa1000000000000000000000000000000000000' },
    { hash: 'bbb2000000000000000000000000000000000000' },
    { hash: 'ccc3000000000000000000000000000000000000' },
    { hash: 'ddd4000000000000000000000000000000000000' },
];

// Shorter, semantically-named fixture for state-machine tests that don't
// care about specific shas.
const shortCommits = [
    { hash: 'tip' }, // 0 = newest
    { hash: 'mid' }, // 1
    { hash: 'old' }, // 2 = oldest
];

function makeForView({ activeDiffFrom = 'HEAD', activeCommitHash = null, branch = 'main', commits = [], branchBase = null } = {}) {
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
    afterEach(() => {
        delete window.Flux;
    });

    it('auto-unticks working tree when the tip commit is unticked', () => {
        const a = makeAlpine({ commits: shortCommits });
        a.workingTreeSelected = true;
        a.selectedHashes = ['tip', 'mid', 'old'];

        a.toggleSelection('tip', 0, noopEvent());

        expect(a.selectedHashes).toEqual(['mid', 'old']);
        expect(a.workingTreeSelected).toBe(false);
    });

    it('keeps working tree selected when a non-tip commit is unticked', () => {
        const a = makeAlpine({ commits: shortCommits });
        a.workingTreeSelected = true;
        a.selectedHashes = ['tip', 'mid', 'old'];

        a.toggleSelection('old', 2, noopEvent());

        expect(a.selectedHashes).toEqual(['tip', 'mid']);
        expect(a.workingTreeSelected).toBe(true);
    });

    it('does not affect a working-tree-only selection', () => {
        const a = makeAlpine({ commits: shortCommits });
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

        const a = makeAlpine({ commits: shortCommits });
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

        const a = makeAlpine({ commits: shortCommits });
        a.workingTreeSelected = true;
        a.selectedHashes = ['tip'];

        // Untick a commit that's not in the selection — adds it, doesn't violate.
        a.toggleSelection('mid', 1, noopEvent());

        expect(toast).not.toHaveBeenCalled();
        expect(a.workingTreeSelected).toBe(true);
    });
});

describe('shift+click range — WT-then-commit ordering', () => {
    afterEach(() => {
        delete window.Flux;
    });

    it('WT-first then shift+click commit N selects WT + commits[0..N]', () => {
        const a = makeAlpine({ commits: shortCommits });

        a.toggleWorkingTreeSelection(noopEvent());
        a.toggleSelection('mid', 1, shiftEvent());

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['tip', 'mid']);
    });

    it('WT-first then shift+click the tip selects WT + [tip]', () => {
        const a = makeAlpine({ commits: shortCommits });

        a.toggleWorkingTreeSelection(noopEvent());
        a.toggleSelection('tip', 0, shiftEvent());

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['tip']);
    });

    it('WT-first then shift+click the oldest selects WT + every commit', () => {
        const a = makeAlpine({ commits: shortCommits });

        a.toggleWorkingTreeSelection(noopEvent());
        a.toggleSelection('old', 2, shiftEvent());

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['tip', 'mid', 'old']);
    });

    it('does not trigger WT auto-untick - the range always includes the tip', () => {
        const toast = vi.fn();
        window.Flux = { toast };

        const a = makeAlpine({ commits: shortCommits });
        a.toggleWorkingTreeSelection(noopEvent());
        a.toggleSelection('old', 2, shiftEvent());

        // _enforceWorkingTreeTipAnchor is not called from the shift branch,
        // but even if it were, the range commits[0..2] includes the tip so
        // the invariant holds. Either way: no toast, WT stays.
        expect(toast).not.toHaveBeenCalled();
        expect(a.workingTreeSelected).toBe(true);
    });

    it('keeps WT as the anchor across consecutive shift+clicks', () => {
        const a = makeAlpine({ commits: shortCommits });

        a.toggleWorkingTreeSelection(noopEvent());
        a.toggleSelection('mid', 1, shiftEvent());
        // Second shift+click on a closer commit should still range from WT,
        // not from `mid`. Anchor must not have moved.
        a.toggleSelection('old', 2, shiftEvent());

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['tip', 'mid', 'old']);
        expect(a.lastSelectionAnchorIsWT).toBe(true);
        expect(a.lastSelectionIndex).toBe(-1);
    });

    it('preserves WT-anchor selection when re-shifting backwards (Set merge keeps prior hashes)', () => {
        const a = makeAlpine({ commits: shortCommits });

        a.toggleWorkingTreeSelection(noopEvent());
        a.toggleSelection('old', 2, shiftEvent());
        // Re-shift to a closer commit: range shrinks, but Set merge means
        // selection only grows. Matches existing commit-anchor behavior.
        a.toggleSelection('mid', 1, shiftEvent());

        expect(a.selectedHashes).toEqual(['tip', 'mid', 'old']);
    });

    it('plain click on a commit after WT clears the WT anchor', () => {
        const a = makeAlpine({ commits: shortCommits });

        a.toggleWorkingTreeSelection(noopEvent());
        // Plain (non-shift) click on a commit advances the anchor to that
        // commit. Subsequent shift+click must use the commit anchor, not WT.
        a.toggleSelection('mid', 1, noopEvent());
        a.toggleSelection('old', 2, shiftEvent());

        expect(a.lastSelectionAnchorIsWT).toBe(false);
        expect(a.lastSelectionIndex).toBe(1);
        // Range mid..old, NOT WT + commits[0..2]:
        expect(a.selectedHashes.sort()).toEqual(['mid', 'old']);
    });

    it('unticking WT (single click) keeps WT as the anchor, so shift+click re-ticks WT', () => {
        const a = makeAlpine({ commits: shortCommits });

        a.toggleWorkingTreeSelection(noopEvent()); // tick WT
        a.toggleWorkingTreeSelection(noopEvent()); // untick WT
        expect(a.workingTreeSelected).toBe(false);
        expect(a.lastSelectionAnchorIsWT).toBe(true);

        // Shift+click commit after untick: WT anchor still active, so range
        // re-ticks WT and extends. Mirrors commit-side "untick B, shift+click
        // C re-ticks B and adds C".
        a.toggleSelection('mid', 1, shiftEvent());

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['tip', 'mid']);
    });
});

describe('shift+click range — commit-then-WT ordering (regression)', () => {
    it('commit anchor + shift+click WT extends from WT through commits[0..lastIdx]', () => {
        const a = makeAlpine({ commits: shortCommits });

        a.toggleSelection('mid', 1, noopEvent()); // anchor = idx 1
        a.toggleWorkingTreeSelection(shiftEvent());

        expect(a.workingTreeSelected).toBe(true);
        // Range from WT down through idx 1 = [tip, mid].
        expect(a.selectedHashes.sort()).toEqual(['mid', 'tip']);
    });

    it('commit anchor takes precedence over WT-anchor flag when shift+click WT fires', () => {
        const a = makeAlpine({ commits: shortCommits });

        // Click WT (sets WT anchor), then plain-click commit (clears WT anchor,
        // sets commit anchor), then shift+click WT.
        a.toggleWorkingTreeSelection(noopEvent());
        a.toggleSelection('mid', 1, noopEvent());
        expect(a.workingTreeSelected).toBe(false);
        expect(a.lastSelectionAnchorIsWT).toBe(false);
        expect(a.lastSelectionIndex).toBe(1);

        a.toggleWorkingTreeSelection(shiftEvent());

        // Should use commit anchor (idx 1), pulling in tip..mid + WT.
        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes.sort()).toEqual(['mid', 'tip']);
    });
});

describe('shift+click anchor — clear/since-base/rehydrate resets', () => {
    it('clearSelection wipes the WT anchor', () => {
        const a = makeAlpine({ commits: shortCommits });
        a.toggleWorkingTreeSelection(noopEvent());
        expect(a.lastSelectionAnchorIsWT).toBe(true);

        a.clearSelection();

        expect(a.lastSelectionAnchorIsWT).toBe(false);
        expect(a.lastSelectionIndex).toBe(-1);
    });

    it('selectSinceBase does NOT seed WT as the anchor', () => {
        const branchBase = {
            state: BranchBaseState.Ready,
            baseSha: 'base',
            hashesInRange: ['tip', 'mid'],
        };
        const a = makeAlpine({ commits: shortCommits, branchBase });

        a.selectSinceBase();

        // Bulk action, not a user click on WT. A subsequent shift+click on a
        // commit should NOT extend a WT range from a stale bulk-fill.
        expect(a.workingTreeSelected).toBe(true);
        expect(a.lastSelectionAnchorIsWT).toBe(false);
        expect(a.lastSelectionIndex).toBe(-1);
    });

    it('after selectSinceBase, shift+click commit is a noop (no anchor to extend from)', () => {
        const branchBase = {
            state: BranchBaseState.Ready,
            baseSha: 'base',
            hashesInRange: ['tip', 'mid'],
        };
        const a = makeAlpine({ commits: shortCommits, branchBase });

        a.selectSinceBase();
        // No anchor: shift branch short-circuits to single-toggle.
        a.toggleSelection('old', 2, shiftEvent());

        // 'old' was added as a single toggle; WT got auto-unticked because the
        // tip-anchor invariant fires for the single-toggle branch.
        expect(a.selectedHashes.sort()).toEqual(['mid', 'old', 'tip']);
        expect(a.lastSelectionAnchorIsWT).toBe(false);
        expect(a.lastSelectionIndex).toBe(2);
    });
});

describe('shift+click after rehydrate', () => {
    it('rehydrate on a WT-only view seeds WT as the shift anchor', () => {
        const a = makeForView({ commits });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.lastSelectionAnchorIsWT).toBe(true);

        a.toggleSelection('bbb2', 1, shiftEvent());

        expect(a.selectedHashes).toEqual(['aaa1', 'bbb2']);
        expect(a.workingTreeSelected).toBe(true);
    });

    it('rehydrate on a /rw view seeds WT as the shift anchor and preserves the existing selection', () => {
        const a = makeForView({
            activeDiffFrom: 'ccc3',
            commits,
            branchBase: { state: BranchBaseState.Ready, baseSha: 'other', hashesInRange: [] },
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['aaa1', 'bbb2']);
        expect(a.lastSelectionAnchorIsWT).toBe(true);

        // Shift+click on a commit further down merges new hashes into the
        // existing rehydrated selection (Set semantics).
        a.toggleSelection('ccc3', 2, shiftEvent());

        expect(a.selectedHashes.sort()).toEqual(['aaa1', 'bbb2', 'ccc3']);
        expect(a.workingTreeSelected).toBe(true);
    });

    it('rehydrate on a commit view does NOT seed WT as anchor', () => {
        const a = makeForView({
            activeCommitHash: 'bbb2',
            activeDiffFrom: 'ccc3',
            commits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.lastSelectionAnchorIsWT).toBe(false);
        expect(a.lastSelectionIndex).toBe(-1);
    });
});

describe('_rehydrateSelectionFromActiveView', () => {
    it('does nothing when the picker is not on the current branch', () => {
        const a = makeForView({ branch: 'feature/x', activeCommitHash: 'aaa1' });
        a._rehydrateSelectionFromActiveView();

        expect(a.selectedHashes).toEqual([]);
        expect(a.workingTreeSelected).toBe(false);
    });

    it('selects working tree only when viewing /p/{slug}', () => {
        const a = makeForView();
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual([]);
    });

    it('selects WT + base..HEAD hashes from branchBase when viewing /rw/{baseSha}', () => {
        const a = makeForView({
            activeDiffFrom: 'base-sha',
            commits,
            branchBase: { state: BranchBaseState.Ready, baseSha: 'base-sha', hashesInRange: ['aaa1', 'bbb2'] },
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['aaa1', 'bbb2']);
    });

    it('selects WT + commits through a parent-suffixed from ref', () => {
        const a = makeForView({
            activeDiffFrom: longCommits[1].hash + '^',
            commits: longCommits,
            branchBase: { state: BranchBaseState.Ready, baseSha: 'other', hashesInRange: [] },
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual([
            longCommits[0].hash,
            longCommits[1].hash,
        ]);
    });

    it('selects WT + commits through a parent-suffixed short from ref', () => {
        const a = makeForView({
            activeDiffFrom: 'bbb2^',
            commits: longCommits,
            branchBase: { state: BranchBaseState.Ready, baseSha: 'other', hashesInRange: [] },
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual([
            longCommits[0].hash,
            longCommits[1].hash,
        ]);
    });

    it('selects WT + commits after a unique short base ref', () => {
        const a = makeForView({
            activeDiffFrom: 'ccc3',
            commits: longCommits,
            branchBase: { state: BranchBaseState.Ready, baseSha: 'other', hashesInRange: [] },
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual([
            longCommits[0].hash,
            longCommits[1].hash,
        ]);
    });

    it('falls back to slicing loaded commits for /rw/{sha} when sha is not the configured base', () => {
        const a = makeForView({
            activeDiffFrom: 'ccc3',
            commits,
            branchBase: { state: BranchBaseState.Ready, baseSha: 'other', hashesInRange: [] },
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(true);
        expect(a.selectedHashes).toEqual(['aaa1', 'bbb2']);
    });

    it('selects just the tip for a single commit view (/c/{hash})', () => {
        const a = makeForView({
            activeCommitHash: 'bbb2',
            activeDiffFrom: 'ccc3', // parent of bbb2 in this fixture
            commits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.selectedHashes).toEqual(['bbb2']);
    });

    it('selects a single commit view when the active commit is a unique short ref', () => {
        const a = makeForView({
            activeCommitHash: 'bbb2',
            activeDiffFrom: 'ccc3',
            commits: longCommits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.selectedHashes).toEqual([longCommits[1].hash]);
    });

    it('selects every commit in (from, to] for an explicit range view (/r/{from}..{to})', () => {
        const a = makeForView({
            activeCommitHash: 'aaa1',
            activeDiffFrom: 'ddd4',
            commits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.selectedHashes).toEqual(['aaa1', 'bbb2', 'ccc3']);
    });

    it('selects every commit in a short explicit range', () => {
        const a = makeForView({
            activeCommitHash: 'bbb2',
            activeDiffFrom: 'ddd4',
            commits: longCommits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.selectedHashes).toEqual([
            longCommits[1].hash,
            longCommits[2].hash,
        ]);
    });

    it('includes the boundary commit when an explicit range uses a parent-suffixed from ref', () => {
        const a = makeForView({
            activeCommitHash: 'aaa1',
            activeDiffFrom: 'ccc3^',
            commits: longCommits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.selectedHashes).toEqual([
            longCommits[0].hash,
            longCommits[1].hash,
            longCommits[2].hash,
        ]);
    });

    it('selects every loaded commit when the range starts at the empty tree', () => {
        const a = makeForView({
            activeCommitHash: longCommits[0].hash,
            activeDiffFrom: '4b825dc642cb6eb9a060e54bf8d69288fbee4904',
            commits: longCommits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.workingTreeSelected).toBe(false);
        expect(a.selectedHashes).toEqual(longCommits.map(commit => commit.hash));
    });

    it('falls back to [tip] when the range endpoints are not in the loaded commits', () => {
        const a = makeForView({
            activeCommitHash: 'unknown-tip',
            activeDiffFrom: 'unknown-from',
            commits,
        });
        a._rehydrateSelectionFromActiveView();

        expect(a.selectedHashes).toEqual(['unknown-tip']);
    });
});

describe('_scrollActiveCommitIntoView', () => {
    function makeWithList({ activeCommitHash = null, activeDiffFrom = 'HEAD', branch = 'main', commitList = commits } = {}) {
        const a = createBranchExplorer({
            currentBranch: 'main',
            activeCommitHash,
            activeDiffFrom,
            projectSlug: 'p',
            branches: { local: [], remote: [] },
        });
        a.selectedBranch = branch;
        a.$wire = { commits: commitList };
        const rows = new Map();
        const make = (hash) => ({ scrollIntoView: vi.fn(), getAttribute: () => hash });
        for (const c of commitList) rows.set(c.hash, make(c.hash));
        a.$refs = {
            commitList: {
                querySelector: (sel) => {
                    const m = sel.match(/^\[data-commit-hash="(.+)"\]$/);
                    return m ? rows.get(m[1]) ?? null : null;
                },
            },
        };
        return { a, rows };
    }

    it('does nothing when the picker is not on the current branch', () => {
        const { a, rows } = makeWithList({ branch: 'feature/x', activeCommitHash: 'aaa1' });
        a._scrollActiveCommitIntoView();
        expect(rows.get('aaa1').scrollIntoView).not.toHaveBeenCalled();
    });

    it('scrolls to the active commit for /c and /r views', () => {
        const { a, rows } = makeWithList({ activeCommitHash: 'ddd4', activeDiffFrom: 'HEAD' });
        a._scrollActiveCommitIntoView();
        expect(rows.get('ddd4').scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
    });

    it('scrolls to the active commit when the active ref is short', () => {
        const { a, rows } = makeWithList({ activeCommitHash: 'ddd4', commitList: longCommits });
        a._scrollActiveCommitIntoView();
        expect(rows.get(longCommits[3].hash).scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
    });

    it('scrolls to the base sha for /rw views (so the bottom of the range is visible)', () => {
        const { a, rows } = makeWithList({ activeCommitHash: null, activeDiffFrom: 'ccc3' });
        a._scrollActiveCommitIntoView();
        expect(rows.get('ccc3').scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
    });

    it('scrolls to the boundary commit for /rw parent-suffixed views', () => {
        const { a, rows } = makeWithList({
            activeCommitHash: null,
            activeDiffFrom: 'ccc3^',
            commitList: longCommits,
        });
        a._scrollActiveCommitIntoView();
        expect(rows.get(longCommits[2].hash).scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
    });

    it('does nothing for working-tree-only views', () => {
        const { a, rows } = makeWithList({ activeCommitHash: null, activeDiffFrom: 'HEAD' });
        a._scrollActiveCommitIntoView();
        for (const row of rows.values()) {
            expect(row.scrollIntoView).not.toHaveBeenCalled();
        }
    });

    it('is a noop when the anchor commit is not in the loaded list', () => {
        const { a } = makeWithList({ activeCommitHash: 'unknown', activeDiffFrom: 'HEAD' });
        expect(() => a._scrollActiveCommitIntoView()).not.toThrow();
    });
});

describe('isActiveCommit', () => {
    it('matches full refs and unique short refs', () => {
        const a = makeForView({ activeCommitHash: 'bbb2', commits: longCommits });

        expect(a.isActiveCommit(longCommits[0].hash)).toBe(false);
        expect(a.isActiveCommit(longCommits[1].hash)).toBe(true);
    });

    it('does not match ambiguous short refs', () => {
        const ambiguousCommits = [
            { hash: 'abcd000000000000000000000000000000000000' },
            { hash: 'abcd111111111111111111111111111111111111' },
        ];
        const a = makeForView({ activeCommitHash: 'abcd', commits: ambiguousCommits });

        expect(a.isActiveCommit(ambiguousCommits[0].hash)).toBe(false);
        expect(a.isActiveCommit(ambiguousCommits[1].hash)).toBe(false);
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

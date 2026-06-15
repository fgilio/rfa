import { beforeEach, describe, expect, it } from 'vitest';
import copyPathsButton from '../../public/js/copy-paths-button.js';

const { copyPathsButton: factory } = copyPathsButton;

function bulkScope(extra = {}) {
    const wireCalls = [];
    return {
        $wire: { copyVisiblePaths: (kind) => wireCalls.push(kind) },
        _wireCalls: wireCalls,
        ...extra,
    };
}

function attach(scope, mode = 'bulk', singlePath = '', repoPath = '') {
    // Object.assign would evaluate `get primaryLabel()` once on the factory's
    // return value (where the parent-scope props don't exist) and copy the
    // resulting string. Use descriptors so the getter stays a getter.
    const f = factory({ mode, singlePath, repoPath });
    Object.defineProperties(scope, Object.getOwnPropertyDescriptors(f));
    return scope;
}

// Fakes the $el.closest('[data-testid="review-component"]') lookup so the
// button reads its visible count from the review root the morph keeps current.
function elInReviewRoot(entries) {
    const root = { dataset: { visibleFileEntries: JSON.stringify(entries) } };
    return { closest: (sel) => (sel === '[data-testid="review-component"]' ? root : null) };
}

describe('copyPathsButton — bulk mode', () => {
    let component;

    beforeEach(() => {
        component = attach(bulkScope(), 'bulk');
        component.$el = elInReviewRoot([
            { id: 'a', path: 'app/Foo.php' },
            { id: 'b', path: 'app/Bar.php' },
            { id: 'c', path: 'tests/Baz.php' },
        ]);
    });

    it('left-click delegates the relative copy to the server', () => {
        component.onClick({ button: 0 });

        expect(component._wireCalls).toEqual(['relative']);
    });

    it('menu items delegate each kind to the server without building text client-side', () => {
        component.copy('name');
        component.copy('relative');
        component.copy('full');

        expect(component._wireCalls).toEqual(['name', 'relative', 'full']);
    });

    it('ignores non-left clicks', () => {
        component.onClick({ button: 2 });

        expect(component._wireCalls).toEqual([]);
    });

    it('labels pluralise by the live visible count read from the review root', () => {
        expect(component.primaryLabel).toBe('Copy 3 relative paths');
        expect(component.nameLabel).toBe('Copy 3 file names');
        expect(component.fullLabel).toBe('Copy 3 full paths');
    });

    it('labels singularise for one visible file', () => {
        component.$el = elInReviewRoot([{ id: 'a', path: 'app/Foo.php' }]);

        expect(component.primaryLabel).toBe('Copy relative path');
        expect(component.nameLabel).toBe('Copy file name');
        expect(component.relativeLabel).toBe('Copy relative path');
        expect(component.fullLabel).toBe('Copy full path');
    });

    it('reports zero when no review root is present and still delegates the copy', () => {
        component.$el = { closest: () => null };

        expect(component.bulkVisibleCount).toBe(0);

        // The server owns the visible set, so the button delegates regardless of
        // the locally-read count; the server no-ops when nothing is visible.
        component.onClick({ button: 0 });
        expect(component._wireCalls).toEqual(['relative']);
    });
});

describe('copyPathsButton — single mode', () => {
    function singleScope() {
        const dispatched = [];
        // No review-root visibleFileEntries / repoPath — single mode must
        // work on pages without the ⚡review-page Alpine root.
        return {
            $dispatch: (name, detail) => dispatched.push({ name, detail }),
            _dispatched: dispatched,
        };
    }

    it('left-click copies the single path with a singular toast', () => {
        const c = attach(singleScope(), 'single', 'src/widget.ts', '/repo');

        c.onClick({ button: 0 });

        expect(c._dispatched[0].detail.text).toBe('src/widget.ts');
        expect(c._dispatched[0].detail.toast).toBe('Copied relative path');
    });

    it('copyAs("full") prefixes with the init-param repoPath without parent helpers', () => {
        const c = attach(singleScope(), 'single', 'src/widget.ts', '/repo');

        c.copyAs('full');

        expect(c._dispatched[0].detail.text).toBe('/repo/src/widget.ts');
        expect(c._dispatched[0].detail.toast).toBe('Copied full path');
    });

    it('copyAs("name") emits the basename without parent helpers', () => {
        const c = attach(singleScope(), 'single', 'src/widget.ts', '/repo');

        c.copyAs('name');

        expect(c._dispatched[0].detail.text).toBe('widget.ts');
        expect(c._dispatched[0].detail.toast).toBe('Copied file name');
    });

    it('copyAs("full") with no repoPath falls through to the relative path', () => {
        const c = attach(singleScope(), 'single', 'src/widget.ts', '');

        c.copyAs('full');

        expect(c._dispatched[0].detail.text).toBe('src/widget.ts');
    });

    it('trailing slashes on repoPath are normalized', () => {
        const c = attach(singleScope(), 'single', 'src/widget.ts', '/repo///');

        c.copyAs('full');

        expect(c._dispatched[0].detail.text).toBe('/repo/src/widget.ts');
    });

    it('primaryLabel stays singular in single mode regardless of the visible count', () => {
        const c = attach({}, 'single', 'src/widget.ts');
        c.$el = elInReviewRoot([{ id: 'a', path: 'a.php' }, { id: 'b', path: 'b.php' }]);
        expect(c.primaryLabel).toBe('Copy relative path');
    });

    it('menu labels stay singular in single mode regardless of the visible count', () => {
        const c = attach({}, 'single', 'src/widget.ts');
        c.$el = elInReviewRoot([{ id: 'a', path: 'a.php' }, { id: 'b', path: 'b.php' }]);
        expect(c.nameLabel).toBe('Copy file name');
        expect(c.relativeLabel).toBe('Copy relative path');
        expect(c.fullLabel).toBe('Copy full path');
    });
});

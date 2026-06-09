import { beforeEach, describe, expect, it } from 'vitest';
import copyPathsButton from '../../public/js/copy-paths-button.js';

const { copyPathsButton: factory } = copyPathsButton;

function bulkScope(extra = {}) {
    const dispatched = [];
    return {
        sourceFileEntries: [
            { id: 'a', path: 'app/Foo.php' },
            { id: 'b', path: 'app/Bar.php' },
            { id: 'c', path: 'tests/Baz.php' },
        ],
        fileMatchesFilter: () => true,
        visibleFileCount: 3,
        repoPath: '/repo',
        $dispatch: (name, detail) => dispatched.push({ name, detail }),
        ...extra,
        _dispatched: dispatched,
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

function attrRoot(entries, visibleCount) {
    return {
        dataset: {
            sourceFileEntries: JSON.stringify(entries),
            visibleFileCount: String(visibleCount),
        },
    };
}

describe('copyPathsButton — bulk mode', () => {
    let component;

    beforeEach(() => {
        component = attach(bulkScope(), 'bulk');
    });

    it('left-click copies relative paths joined by newlines', () => {
        component.onClick({ button: 0 });

        expect(component._dispatched).toHaveLength(1);
        expect(component._dispatched[0].name).toBe('copy-to-clipboard');
        expect(component._dispatched[0].detail.text).toBe(
            'app/Foo.php\napp/Bar.php\ntests/Baz.php',
        );
        expect(component._dispatched[0].detail.toast).toBe('Copied 3 relative paths');
    });

    it('copyAs("name") emits basenames', () => {
        component.copyAs('name');

        expect(component._dispatched[0].detail.text).toBe('Foo.php\nBar.php\nBaz.php');
        expect(component._dispatched[0].detail.toast).toBe('Copied 3 file names');
    });

    it('copyAs("full") prepends the repo root', () => {
        component.copyAs('full');

        expect(component._dispatched[0].detail.text).toBe(
            '/repo/app/Foo.php\n/repo/app/Bar.php\n/repo/tests/Baz.php',
        );
        expect(component._dispatched[0].detail.toast).toBe('Copied 3 full paths');
    });

    it('primaryLabel pluralises by visibleFileCount', () => {
        component.visibleFileCount = 1;
        expect(component.primaryLabel).toBe('Copy relative path');

        component.visibleFileCount = 5;
        expect(component.primaryLabel).toBe('Copy 5 relative paths');
    });

    it('menu labels singularise when visibleFileCount === 1 and pluralise + show count when > 1', () => {
        component.visibleFileCount = 1;
        expect(component.nameLabel).toBe('Copy file name');
        expect(component.relativeLabel).toBe('Copy relative path');
        expect(component.fullLabel).toBe('Copy full path');

        component.visibleFileCount = 3;
        expect(component.nameLabel).toBe('Copy 3 file names');
        expect(component.relativeLabel).toBe('Copy 3 relative paths');
        expect(component.fullLabel).toBe('Copy 3 full paths');
    });

    it('uses server-visible entries from root data attributes when present', () => {
        component.$root = attrRoot([{ id: 'b', path: 'app/Bar.php' }], 1);

        component.copyAs('relative');

        expect(component._dispatched[0].detail.text).toBe('app/Bar.php');
        expect(component._dispatched[0].detail.toast).toBe('Copied relative path');
        expect(component.primaryLabel).toBe('Copy relative path');
    });
});

describe('copyPathsButton — single mode', () => {
    function singleScope() {
        const dispatched = [];
        // No sourceFileEntries / fileMatchesFilter / repoPath — single mode
        // must work on pages without the ⚡review-page Alpine root.
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

    it('primaryLabel ignores visibleFileCount', () => {
        const c = attach(bulkScope({ visibleFileCount: 99 }), 'single', 'src/widget.ts');
        expect(c.primaryLabel).toBe('Copy relative path');
    });

    it('menu labels stay singular regardless of visibleFileCount on the parent scope', () => {
        const c = attach(bulkScope({ visibleFileCount: 99 }), 'single', 'src/widget.ts');
        expect(c.nameLabel).toBe('Copy file name');
        expect(c.relativeLabel).toBe('Copy relative path');
        expect(c.fullLabel).toBe('Copy full path');
    });
});

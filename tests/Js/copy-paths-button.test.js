import { beforeEach, describe, expect, it } from 'vitest';
import copyPathsButton from '../../public/js/copy-paths-button.js';

const { copyPathsButton: factory } = copyPathsButton;

function bulkScope(extra = {}) {
    const dispatched = [];
    return {
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
        document.body.innerHTML = '';
        component = attach(bulkScope(), 'bulk');
        component.$root = attrRoot([
            { id: 'a', path: 'app/Foo.php' },
            { id: 'b', path: 'app/Bar.php' },
            { id: 'c', path: 'tests/Baz.php' },
        ], 3);
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

    it('primaryLabel pluralises by the number of visible entries', () => {
        component.$root = attrRoot([{ id: 'a', path: 'app/Foo.php' }], 1);
        expect(component.primaryLabel).toBe('Copy relative path');

        component.$root = attrRoot(
            ['a', 'b', 'c', 'd', 'e'].map((id) => ({ id, path: `${id}.php` })),
            5,
        );
        expect(component.primaryLabel).toBe('Copy 5 relative paths');
    });

    it('menu labels singularise for one entry and pluralise + show count for more', () => {
        component.$root = attrRoot([{ id: 'a', path: 'app/Foo.php' }], 1);
        expect(component.nameLabel).toBe('Copy file name');
        expect(component.relativeLabel).toBe('Copy relative path');
        expect(component.fullLabel).toBe('Copy full path');

        component.$root = attrRoot(
            ['a', 'b', 'c'].map((id) => ({ id, path: `${id}.php` })),
            3,
        );
        expect(component.nameLabel).toBe('Copy 3 file names');
        expect(component.relativeLabel).toBe('Copy 3 relative paths');
        expect(component.fullLabel).toBe('Copy 3 full paths');
    });

    it('derives the count from the entries so the label matches the copied lines', () => {
        // A stale or mismatched visible-file-count attribute must never make the
        // label claim a different number than what actually lands on the clipboard.
        component.$root = attrRoot([
            { id: 'a', path: 'app/Foo.php' },
            { id: 'b', path: 'app/Bar.php' },
        ], 2);
        component.$root.dataset.visibleFileCount = '5';

        component.copyAs('relative');

        expect(component.primaryLabel).toBe('Copy 2 relative paths');
        expect(component._dispatched[0].detail.text).toBe('app/Foo.php\napp/Bar.php');
        expect(component._dispatched[0].detail.toast).toBe('Copied 2 relative paths');
    });

    it('reflects narrowed server-visible entries from the data attributes', () => {
        component.$root = attrRoot([{ id: 'b', path: 'app/Bar.php' }], 1);

        component.copyAs('relative');

        expect(component._dispatched[0].detail.text).toBe('app/Bar.php');
        expect(component._dispatched[0].detail.toast).toBe('Copied relative path');
        expect(component.primaryLabel).toBe('Copy relative path');
    });

    it('prefers the review root live visible entries over its own stale attribute', () => {
        // The review root reliably reflects the filter; the button's own
        // attribute can lag inside its Alpine island after a morph. Bulk copy
        // must follow the root so a filtered copy never includes hidden files.
        const root = document.createElement('div');
        root.setAttribute('data-testid', 'review-component');
        root.dataset.visibleFileEntries = JSON.stringify([{ id: 'a', path: 'app/Foo.php' }]);

        const buttonEl = document.createElement('div');
        buttonEl.dataset.sourceFileEntries = JSON.stringify([
            { id: 'a', path: 'app/Foo.php' },
            { id: 'b', path: 'app/Bar.php' },
        ]);
        buttonEl.dataset.visibleFileCount = '2';
        root.appendChild(buttonEl);
        document.body.appendChild(root);

        const c = attach(bulkScope(), 'bulk');
        c.$el = buttonEl;
        c.$root = buttonEl;

        c.copyAs('relative');

        expect(c._dispatched[0].detail.text).toBe('app/Foo.php');
        expect(c._dispatched[0].detail.toast).toBe('Copied relative path');
        expect(c.bulkVisibleCount).toBe(1);
        expect(c.primaryLabel).toBe('Copy relative path');
    });

    it('copies nothing and reports zero when the data attributes are missing', () => {
        component.$root = { dataset: {} };

        component.copyAs('relative');

        expect(component._dispatched).toHaveLength(0);
        expect(component.bulkEntries).toEqual([]);
        expect(component.bulkVisibleCount).toBe(0);
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

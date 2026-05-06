import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import copyPathsButton from '../../public/js/copy-paths-button.js';

const { copyPathsButton: factory, LONG_PRESS_MS } = copyPathsButton;

function bulkScope(extra = {}) {
    const dispatched = [];
    const setState = vi.fn();
    // Stand in for `<ui-dropdown>`. The overlay is its lastElementChild and
    // exposes `_popoverable.setState(true|false)` once Flux has booted.
    const dropdown = {
        lastElementChild: { _popoverable: { setState } },
    };
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
        $refs: { dropdown },
        ...extra,
        _dispatched: dispatched,
        _opened: setState,
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

describe('copyPathsButton — bulk mode', () => {
    let component;

    beforeEach(() => {
        vi.useFakeTimers();
        component = attach(bulkScope(), 'bulk');
    });

    afterEach(() => {
        vi.useRealTimers();
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

    it('long-press past 400ms opens the menu and suppresses the trailing click', () => {
        component.onMouseDown({ button: 0 });
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(component._opened).toHaveBeenCalledOnce();
        expect(component._suppressClick).toBe(true);

        component.onClick({ button: 0 });

        expect(component._dispatched).toHaveLength(0);
        expect(component._suppressClick).toBe(false);
    });

    it('quick mousedown+mouseup cancels the long-press timer', () => {
        component.onMouseDown({ button: 0 });
        vi.advanceTimersByTime(100);
        component.cancelLongPress();
        vi.advanceTimersByTime(LONG_PRESS_MS);

        expect(component._opened).not.toHaveBeenCalled();
        expect(component._suppressClick).toBe(false);
    });

    it('mouseleave during a hold cancels the timer', () => {
        component.onMouseDown({ button: 0 });
        vi.advanceTimersByTime(200);
        component.cancelLongPress();
        vi.advanceTimersByTime(500);

        expect(component._opened).not.toHaveBeenCalled();
    });

    it('non-left mouse buttons do not start the long-press timer', () => {
        component.onMouseDown({ button: 2 });
        vi.advanceTimersByTime(LONG_PRESS_MS * 2);

        expect(component._opened).not.toHaveBeenCalled();
    });

    it('primaryLabel pluralises by visibleFileCount', () => {
        component.visibleFileCount = 1;
        expect(component.primaryLabel).toBe('Copy relative path');

        component.visibleFileCount = 5;
        expect(component.primaryLabel).toBe('Copy 5 relative paths');
    });
});

describe('copyPathsButton — single mode', () => {
    function singleScope() {
        const dispatched = [];
        const setState = vi.fn();
        const dropdown = { lastElementChild: { _popoverable: { setState } } };
        // No sourceFileEntries / fileMatchesFilter / repoPath — single mode
        // must work on pages without the ⚡review-page Alpine root.
        return {
            $dispatch: (name, detail) => dispatched.push({ name, detail }),
            $refs: { dropdown },
            _dispatched: dispatched,
            _opened: setState,
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
});

import { describe, expect, it } from 'vitest';
import zoomShortcuts from '../../public/js/zoom-shortcuts.js';

const { dispatch, register } = zoomShortcuts;

function fakeRoot({ withKeymap = true, withLivewire = true } = {}) {
    const calls = { dispatched: [], registered: new Map(), opts: new Map() };
    const keymap = withKeymap
        ? {
              register(combo, handler, opts) {
                  calls.registered.set(combo, handler);
                  calls.opts.set(combo, opts);
              },
          }
        : null;

    return {
        root: {
            Alpine: { store: () => keymap },
            Livewire: withLivewire
                ? { dispatch: (event, detail) => calls.dispatched.push({ event, detail }) }
                : undefined,
        },
        calls,
    };
}

describe('dispatch', () => {
    it.each(['in', 'out', 'reset'])('routes %s to Livewire as rfa-zoom', (direction) => {
        const { root, calls } = fakeRoot();

        dispatch(root, direction)();

        expect(calls.dispatched).toEqual([
            { event: 'rfa-zoom', detail: { direction } },
        ]);
    });

    it('no-ops gracefully if Livewire is not yet bound', () => {
        const { root } = fakeRoot({ withLivewire: false });

        expect(() => dispatch(root, 'in')()).not.toThrow();
    });
});

describe('register', () => {
    it('binds ⌘=, ⌘⇧+, ⌘-, ⌘0 on the keymap store', () => {
        const { root, calls } = fakeRoot();

        expect(register(root)).toBe(true);
        expect([...calls.registered.keys()]).toEqual(['⌘=', '⌘⇧+', '⌘-', '⌘0']);
    });

    it('binds both shifted and unshifted zoom-in so the canonical ⌘+ combo fires', () => {
        const { root, calls } = fakeRoot();
        register(root);

        calls.registered.get('⌘=')();
        calls.registered.get('⌘⇧+')();

        expect(calls.dispatched.map((c) => c.detail.direction)).toEqual(['in', 'in']);
    });

    it('registered handlers dispatch the matching direction', () => {
        const { root, calls } = fakeRoot();
        register(root);

        calls.registered.get('⌘=')();
        calls.registered.get('⌘-')();
        calls.registered.get('⌘0')();

        expect(calls.dispatched.map((c) => c.detail.direction)).toEqual(['in', 'out', 'reset']);
    });

    it('passes allowInEditable so zoom works while typing in comment inputs', () => {
        const { root, calls } = fakeRoot();
        register(root);

        for (const combo of ['⌘=', '⌘⇧+', '⌘-', '⌘0']) {
            expect(calls.opts.get(combo)).toEqual({ allowInEditable: true });
        }
    });

    it('returns false when the keymap store has not been installed yet', () => {
        const { root } = fakeRoot({ withKeymap: false });

        expect(register(root)).toBe(false);
    });
});

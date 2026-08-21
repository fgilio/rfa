import { afterEach, describe, expect, it, vi } from 'vitest';
import keymapStore from '../../public/js/keymap-store.js';

const { matches, isEditable, install } = keymapStore;

function event(overrides = {}) {
    return {
        key: 'k',
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        altKey: false,
        ...overrides,
    };
}

describe('matches', () => {
    it.each([
        ['⌘K matches meta+k', '⌘K', { key: 'k', metaKey: true }, true],
        ['⌘K matches ctrl+k (cross-platform)', '⌘K', { key: 'k', ctrlKey: true }, true],
        ['⌘K rejects when shift is also pressed', '⌘K', { key: 'k', metaKey: true, shiftKey: true }, false],
        ['⇧⌘K matches meta+shift+k', '⇧⌘K', { key: 'k', metaKey: true, shiftKey: true }, true],
        ['⇧⌘K rejects without shift', '⇧⌘K', { key: 'k', metaKey: true }, false],
        ['⌘K rejects when alt is held', '⌘K', { key: 'k', metaKey: true, altKey: true }, false],
        ['⌘K is case-insensitive on key', '⌘K', { key: 'K', metaKey: true }, true],
        ['⌘K rejects wrong key', '⌘K', { key: 'j', metaKey: true }, false],
        ['⌘K rejects when no modifier is held', '⌘K', { key: 'k' }, false],
        ['⇧⌘K rejects without cmd', '⇧⌘K', { key: 'k', shiftKey: true }, false],
        // ⌘↵ maps the Enter glyph to the KeyboardEvent.key spelling.
        ['⌘↵ matches meta+Enter', '⌘↵', { key: 'Enter', metaKey: true }, true],
        ['⌘↵ matches ctrl+Enter', '⌘↵', { key: 'Enter', ctrlKey: true }, true],
        ['⌘↵ rejects bare Enter', '⌘↵', { key: 'Enter' }, false],
        // Bare-character combos: exact key, no command modifier.
        ['j matches a bare j', 'j', { key: 'j' }, true],
        ['j rejects ⌘+j', 'j', { key: 'j', metaKey: true }, false],
        ['j rejects shift+J (case-sensitive)', 'j', { key: 'J', shiftKey: true }, false],
        ['⇧-letter combo C matches the shifted character', 'C', { key: 'C', shiftKey: true }, true],
        ['C rejects a lowercase c', 'C', { key: 'c' }, false],
        ['? matches its shifted character', '?', { key: '?', shiftKey: true }, true],
        ['/ matches a bare slash', '/', { key: '/' }, true],
        ['[ rejects when alt is held', '[', { key: '[', altKey: true }, false],
        // Hyper combos (⌃ and/or ⌥ named): every modifier flag is matched
        // literally, and the base key falls back to event.code because Option
        // rewrites event.key through the keyboard layout.
        ['⌃⌥⇧⌘S matches the full hyper chord', '⌃⌥⇧⌘S', { key: 's', ctrlKey: true, altKey: true, shiftKey: true, metaKey: true }, true],
        ['⌃⌥⇧⌘S matches the option-mangled key via code', '⌃⌥⇧⌘S', { key: 'ß', code: 'KeyS', ctrlKey: true, altKey: true, shiftKey: true, metaKey: true }, true],
        ['⌃⌥⇧⌘S matches the shifted key spelling', '⌃⌥⇧⌘S', { key: 'S', ctrlKey: true, altKey: true, shiftKey: true, metaKey: true }, true],
        ['⌃⌥⇧⌘S is order-independent', '⇧⌘⌃⌥S', { key: 's', ctrlKey: true, altKey: true, shiftKey: true, metaKey: true }, true],
        ['⌃⌥⇧⌘S rejects a missing ctrl', '⌃⌥⇧⌘S', { key: 's', altKey: true, shiftKey: true, metaKey: true }, false],
        ['⌃⌥⇧⌘S rejects a missing alt', '⌃⌥⇧⌘S', { key: 's', ctrlKey: true, shiftKey: true, metaKey: true }, false],
        ['⌃⌥⇧⌘S rejects a missing shift', '⌃⌥⇧⌘S', { key: 's', ctrlKey: true, altKey: true, metaKey: true }, false],
        ['⌃⌥⇧⌘S rejects a missing cmd', '⌃⌥⇧⌘S', { key: 's', ctrlKey: true, altKey: true, shiftKey: true }, false],
        ['⌃⌥⇧⌘S rejects a bare s', '⌃⌥⇧⌘S', { key: 's' }, false],
        ['⌃⌥⇧⌘S rejects the wrong base key', '⌃⌥⇧⌘S', { key: 'd', code: 'KeyD', ctrlKey: true, altKey: true, shiftKey: true, metaKey: true }, false],
        // The ⌘→Ctrl aliasing is deliberately absent here: Ctrl is part of the
        // chord, so ctrl-without-cmd must not stand in for cmd.
        ['⌃⌥⇧⌘S rejects ctrl standing in for cmd', '⌃⌥⇧⌘S', { key: 's', ctrlKey: true, altKey: true, shiftKey: true, metaKey: false }, false],
        ['⌘S is not matched by the hyper chord', '⌘S', { key: 's', ctrlKey: true, altKey: true, shiftKey: true, metaKey: true }, false],
    ])('%s', (_label, combo, props, expected) => {
        expect(matches(combo, event(props))).toBe(expected);
    });
});

describe('isEditable', () => {
    it('returns true for <input>', () => {
        expect(isEditable(document.createElement('input'))).toBe(true);
    });

    it('returns true for <textarea>', () => {
        expect(isEditable(document.createElement('textarea'))).toBe(true);
    });

    it('returns true for a contenteditable element', () => {
        const div = document.createElement('div');
        // happy-dom may not derive `isContentEditable` from the attribute, so set
        // the property directly — `isEditable` only reads the property.
        Object.defineProperty(div, 'isContentEditable', { value: true });
        expect(isEditable(div)).toBe(true);
    });

    it('returns false for <button>', () => {
        expect(isEditable(document.createElement('button'))).toBe(false);
    });

    it('returns false for null', () => {
        expect(isEditable(null)).toBe(false);
    });

    it('returns false for a plain <div>', () => {
        expect(isEditable(document.createElement('div'))).toBe(false);
    });
});

describe('install', () => {
    afterEach(() => {
        delete window.Alpine;
        delete window.__keymapAttached;
    });

    it('registers the keymap store with Alpine and is idempotent', () => {
        const store = vi.fn();
        window.Alpine = { store };

        expect(install(window)).toBe(true);
        expect(store).toHaveBeenCalledTimes(1);
        expect(store).toHaveBeenCalledWith(
            'keymap',
            expect.objectContaining({
                register: expect.any(Function),
                unregister: expect.any(Function),
                bindings: expect.any(Map),
            })
        );

        expect(install(window)).toBe(false);
        expect(store).toHaveBeenCalledTimes(1);
    });

    it('is a no-op when Alpine is not present and does not poison the flag', () => {
        // If install set the flag before checking Alpine, a later attempt
        // once Alpine is ready would silently no-op.
        expect(install(window)).toBe(false);
        expect(window.__keymapAttached).toBeUndefined();

        const store = vi.fn();
        window.Alpine = { store };
        expect(install(window)).toBe(true);
        expect(store).toHaveBeenCalledTimes(1);
    });
});

describe('dispatch', () => {
    let registeredStore;

    afterEach(() => {
        delete window.Alpine;
        delete window.__keymapAttached;
        registeredStore = undefined;
    });

    function setup() {
        window.Alpine = {
            store: vi.fn((_name, value) => {
                registeredStore = value;
            }),
        };
        install(window);
    }

    it('fires the handler and calls preventDefault on a matching keydown', () => {
        setup();
        const handler = vi.fn();
        registeredStore.register('⌘K', handler);

        const e = new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true });
        window.dispatchEvent(e);

        expect(handler).toHaveBeenCalledTimes(1);
        expect(e.defaultPrevented).toBe(true);
    });

    it('does not fire a non-allowInEditable binding while focused in a textarea', () => {
        setup();
        const handler = vi.fn();
        registeredStore.register('⌘K', handler);

        const textarea = document.createElement('textarea');
        document.body.appendChild(textarea);

        // Dispatch on the textarea so the event target is the textarea.
        const e = new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true });
        textarea.dispatchEvent(e);

        expect(handler).not.toHaveBeenCalled();
        expect(e.defaultPrevented).toBe(false);

        textarea.remove();
    });

    it('fires an allowInEditable binding while focused in a textarea', () => {
        setup();
        const handler = vi.fn();
        registeredStore.register('⌘K', handler, { allowInEditable: true });

        const textarea = document.createElement('textarea');
        document.body.appendChild(textarea);

        const e = new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true });
        textarea.dispatchEvent(e);

        expect(handler).toHaveBeenCalledTimes(1);

        textarea.remove();
    });

    it('routes a keydown to only the matching combo, leaving other handlers untouched', () => {
        setup();
        const cmdK = vi.fn();
        const cmdJ = vi.fn();
        registeredStore.register('⌘K', cmdK);
        registeredStore.register('⌘J', cmdJ);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true }));

        expect(cmdK).toHaveBeenCalledTimes(1);
        expect(cmdJ).not.toHaveBeenCalled();
    });

    it('replaces the prior handler when the same combo is registered twice', () => {
        // Map-set semantics: re-registering by key overwrites. Without this,
        // hydration on SPA navigations would stack handlers per visit.
        setup();
        const first = vi.fn();
        const second = vi.fn();
        registeredStore.register('⌘K', first);
        registeredStore.register('⌘K', second);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true }));

        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledTimes(1);
    });

    it('clears bindings on livewire:navigating so stale shortcuts do not fire', () => {
        setup();
        const handler = vi.fn();
        registeredStore.register('⌘K', handler);

        document.dispatchEvent(new Event('livewire:navigating'));

        const e = new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true });
        window.dispatchEvent(e);

        expect(handler).not.toHaveBeenCalled();
        expect(registeredStore.bindings.size).toBe(0);
    });

    it('repeats the handler for a held key by default', () => {
        // Hold `j` to walk the file list — the navigation shortcuts depend on
        // auto-repeat, so it must stay on unless a binding opts out.
        setup();
        const handler = vi.fn();
        registeredStore.register('j', handler);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', bubbles: true, cancelable: true }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', repeat: true, bubbles: true, cancelable: true }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', repeat: true, bubbles: true, cancelable: true }));

        expect(handler).toHaveBeenCalledTimes(3);
    });

    it('collapses a held key to one call when ignoreAutoRepeat is set', () => {
        // A toggle flipped once per repeat would flicker and settle on whichever
        // parity the key release lands in.
        setup();
        const handler = vi.fn();
        registeredStore.register('⌃⌥⇧⌘S', handler, { ignoreAutoRepeat: true });

        const press = (repeat) =>
            new KeyboardEvent('keydown', {
                key: 's',
                ctrlKey: true,
                altKey: true,
                shiftKey: true,
                metaKey: true,
                repeat,
                bubbles: true,
                cancelable: true,
            });

        window.dispatchEvent(press(false));
        const held = press(true);
        window.dispatchEvent(held);
        window.dispatchEvent(press(true));

        expect(handler).toHaveBeenCalledTimes(1);
        // The repeats are still swallowed, so the held chord never reaches the
        // page underneath.
        expect(held.defaultPrevented).toBe(true);
    });

    it('fires again on the next fresh keydown after a held run', () => {
        setup();
        const handler = vi.fn();
        registeredStore.register('⌃⌥⇧⌘S', handler, { ignoreAutoRepeat: true });

        const press = (repeat) =>
            new KeyboardEvent('keydown', {
                key: 's',
                ctrlKey: true,
                altKey: true,
                shiftKey: true,
                metaKey: true,
                repeat,
                bubbles: true,
                cancelable: true,
            });

        window.dispatchEvent(press(false));
        window.dispatchEvent(press(true));
        window.dispatchEvent(press(false));

        expect(handler).toHaveBeenCalledTimes(2);
    });
});

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
});

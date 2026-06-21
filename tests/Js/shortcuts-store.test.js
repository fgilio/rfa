import { describe, expect, it, vi } from 'vitest';
import shortcutsStore from '../../public/js/shortcuts-store.js';

const { createStore } = shortcutsStore;

const catalog = {
    'project-picker.toggle': { combo: '⌘K', label: 'Switch repository', allowInEditable: true },
    'review.collapse-all': { combo: 'C', display: '⇧C', label: 'Collapse all files' },
    'review.next-file': { combo: 'j', label: 'Next file' },
};

function setup() {
    const keymap = { register: vi.fn(), unregister: vi.fn() };
    const store = createStore(catalog, () => keymap);
    return { keymap, store };
}

describe('register', () => {
    it('delegates to keymap with the catalog combo and allowInEditable flag', () => {
        const { keymap, store } = setup();
        const handler = vi.fn();

        store.register('project-picker.toggle', handler);

        expect(keymap.register).toHaveBeenCalledWith('⌘K', handler, { allowInEditable: true });
    });

    it('defaults allowInEditable to false when the catalog omits it', () => {
        const { keymap, store } = setup();
        const handler = vi.fn();

        store.register('review.next-file', handler);

        expect(keymap.register).toHaveBeenCalledWith('j', handler, { allowInEditable: false });
    });

    it('warns and skips an unknown id instead of registering a dead combo', () => {
        const { keymap, store } = setup();
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        store.register('does.not.exist', vi.fn());

        expect(keymap.register).not.toHaveBeenCalled();
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
    });
});

describe('unregister', () => {
    it('removes the catalog combo from keymap', () => {
        const { keymap, store } = setup();

        store.unregister('project-picker.toggle');

        expect(keymap.unregister).toHaveBeenCalledWith('⌘K');
    });

    it('is a no-op for an unknown id', () => {
        const { keymap, store } = setup();

        store.unregister('does.not.exist');

        expect(keymap.unregister).not.toHaveBeenCalled();
    });
});

describe('combo / display', () => {
    it('returns the combo used for matching', () => {
        const { store } = setup();
        expect(store.combo('review.collapse-all')).toBe('C');
        expect(store.combo('review.next-file')).toBe('j');
    });

    it('prefers the display override, falling back to the combo', () => {
        const { store } = setup();
        expect(store.display('review.collapse-all')).toBe('⇧C');
        expect(store.display('project-picker.toggle')).toBe('⌘K');
    });

    it('returns empty strings for an unknown id', () => {
        const { store } = setup();
        expect(store.combo('nope')).toBe('');
        expect(store.display('nope')).toBe('');
    });
});

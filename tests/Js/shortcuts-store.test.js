import { describe, expect, it, vi } from 'vitest';
import shortcutsStore from '../../public/js/shortcuts-store.js';

const { createStore } = shortcutsStore;

const catalog = {
    'project-picker.toggle': { combo: '⌘K', label: 'Switch repository', allowInEditable: true },
    'sidebar.toggle': { combo: '⌃⌥⇧⌘S', label: 'Toggle sidebar', allowInEditable: true, ignoreAutoRepeat: true },
    'review.collapse-all': { combo: 'C', display: '⇧C', label: 'Collapse all files' },
    'review.next-file': { combo: 'j', label: 'Next file' },
};

function setup() {
    const keymap = { register: vi.fn(), unregister: vi.fn() };
    const store = createStore(catalog, () => keymap);
    return { keymap, store };
}

describe('register', () => {
    it('delegates to keymap with the catalog combo and behaviour flags', () => {
        const { keymap, store } = setup();
        const handler = vi.fn();

        store.register('project-picker.toggle', handler);

        expect(keymap.register).toHaveBeenCalledWith('⌘K', handler, {
            allowInEditable: true,
            ignoreAutoRepeat: false,
        });
    });

    it('defaults the behaviour flags to false when the catalog omits them', () => {
        const { keymap, store } = setup();
        const handler = vi.fn();

        store.register('review.next-file', handler);

        expect(keymap.register).toHaveBeenCalledWith('j', handler, {
            allowInEditable: false,
            ignoreAutoRepeat: false,
        });
    });

    it('passes ignoreAutoRepeat through so a toggle ignores a held chord', () => {
        // The flag lives in config/shortcuts.php, not at the call site, so a
        // shortcut can't register with repeat semantics its catalog entry denies.
        const { keymap, store } = setup();
        const handler = vi.fn();

        store.register('sidebar.toggle', handler);

        expect(keymap.register).toHaveBeenCalledWith('⌃⌥⇧⌘S', handler, {
            allowInEditable: true,
            ignoreAutoRepeat: true,
        });
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

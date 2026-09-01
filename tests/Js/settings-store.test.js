import { beforeEach, describe, expect, it, vi } from 'vitest';
import settingsStore from '../../public/js/settings-store.js';

const { install, normalizeSidebarWidth, parseSidebarWidth, restoreSidebarWidth } = settingsStore;

describe('settings store prepaint state', () => {
    let root;

    beforeEach(() => {
        const values = new Map();

        root = {
            document,
            localStorage: {
                getItem: (key) => values.get(key) ?? null,
                setItem: (key, value) => values.set(key, value),
                removeItem: (key) => values.delete(key),
            },
        };
        document.documentElement.style.removeProperty('--sidebar-w');
    });

    it('restores the persisted sidebar width before Alpine starts', () => {
        root.localStorage.setItem('rfa.sidebarWidth', JSON.stringify(524));

        expect(restoreSidebarWidth(root)).toBe(524);
        expect(document.documentElement.style.getPropertyValue('--sidebar-w')).toBe('524px');
    });

    it.each([
        ['minimum', 120, 200],
        ['maximum', 800, 600],
    ])('clamps a width below the %s', (_, stored, expected) => {
        root.localStorage.setItem('rfa.sidebarWidth', JSON.stringify(stored));

        expect(restoreSidebarWidth(root)).toBe(expected);
        expect(root.localStorage.getItem('rfa.sidebarWidth')).toBe(JSON.stringify(expected));
        expect(document.documentElement.style.getPropertyValue('--sidebar-w')).toBe(`${expected}px`);
    });

    it.each(['not-json', '"wide"', 'null', '-1'])('rejects an invalid width: %s', (stored) => {
        root.localStorage.setItem('rfa.sidebarWidth', stored);

        expect(parseSidebarWidth(stored)).toBeNull();
        expect(restoreSidebarWidth(root)).toBe(288);
        expect(root.localStorage.getItem('rfa.sidebarWidth')).toBeNull();
        expect(document.documentElement.style.getPropertyValue('--sidebar-w')).toBe('288px');
    });

    it('normalizes every width through one policy', () => {
        expect(normalizeSidebarWidth(120)).toBe(200);
        expect(normalizeSidebarWidth(524)).toBe(524);
        expect(normalizeSidebarWidth(800)).toBe(600);
        expect(normalizeSidebarWidth(-1)).toBe(200);
        expect(normalizeSidebarWidth(Number.NaN)).toBeNull();
        expect(normalizeSidebarWidth('524')).toBeNull();
    });

    it('migrates the old key through the same width policy', () => {
        root.localStorage.setItem('rfa-sidebar-width', '800');

        install(root);

        expect(root.localStorage.getItem('rfa-sidebar-width')).toBeNull();
        expect(root.localStorage.getItem('rfa.sidebarWidth')).toBe(JSON.stringify(600));
        expect(document.documentElement.style.getPropertyValue('--sidebar-w')).toBe('600px');
    });

    it('preserves migration precedence when both keys exist', () => {
        root.localStorage.setItem('rfa.sidebarWidth', JSON.stringify(320));
        root.localStorage.setItem('rfa-sidebar-width', '480');

        install(root);

        expect(root.localStorage.getItem('rfa.sidebarWidth')).toBe(JSON.stringify(480));
        expect(document.documentElement.style.getPropertyValue('--sidebar-w')).toBe('480px');
    });

    it('owns sidebar width commit and reset operations', () => {
        let store;
        root.Alpine = {
            $persist: (value) => ({ as: () => value }),
            store: vi.fn((_, value) => { store = value; }),
        };

        install(root);

        expect(store.setSidebarWidth(800)).toBe(true);
        expect(store.sidebarWidth).toBe(600);
        expect(document.documentElement.style.getPropertyValue('--sidebar-w')).toBe('600px');

        expect(store.setSidebarWidth('wide')).toBe(false);
        expect(store.sidebarWidth).toBe(600);

        expect(store.resetSidebarWidth()).toBe(true);
        expect(store.sidebarWidth).toBe(288);
        expect(document.documentElement.style.getPropertyValue('--sidebar-w')).toBe('288px');
    });
});

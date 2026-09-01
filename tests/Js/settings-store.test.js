import { beforeEach, describe, expect, it } from 'vitest';
import settingsStore from '../../public/js/settings-store.js';

const { parseSidebarWidth, restoreSidebarWidth } = settingsStore;

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
});

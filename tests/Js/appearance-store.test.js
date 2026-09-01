import { describe, expect, it } from 'vitest';
import appearanceStore from '../../public/js/appearance-store.js';

const { persistSelectedAppearance, restoreSelectedAppearance } = appearanceStore;

function createRoot(cookie = '') {
    const values = new Map();
    const cookieWrites = [];

    return {
        cookieWrites,
        document: {
            get cookie() {
                return cookie;
            },
            set cookie(value) {
                cookieWrites.push(value);
            },
        },
        localStorage: {
            getItem: (key) => values.get(key) ?? null,
            setItem: (key, value) => values.set(key, value),
        },
    };
}

describe('appearance store', () => {
    it('restores an explicit appearance before Flux initializes', () => {
        const root = createRoot('rfa_appearance=dark');

        expect(restoreSelectedAppearance(root)).toBe(true);
        expect(root.localStorage.getItem('flux.appearance')).toBe('dark');
    });

    it('preserves Flux storage when it already has an appearance', () => {
        const root = createRoot('rfa_appearance=dark');
        root.localStorage.setItem('flux.appearance', 'light');

        expect(restoreSelectedAppearance(root)).toBe(false);
        expect(root.localStorage.getItem('flux.appearance')).toBe('light');
    });

    it('keeps system appearance represented by absent Flux storage', () => {
        const root = createRoot('rfa_appearance=system');

        expect(restoreSelectedAppearance(root)).toBe(false);
        expect(root.localStorage.getItem('flux.appearance')).toBeNull();
    });

    it('persists the selected appearance and retires the legacy theme cookie', () => {
        const root = createRoot();

        expect(persistSelectedAppearance(root, 'dark')).toBe(true);
        expect(root.cookieWrites).toEqual([
            'rfa_appearance=dark;path=/;max-age=31536000;SameSite=Lax',
            'rfa_theme=;path=/;max-age=0;SameSite=Lax',
        ]);
    });
});

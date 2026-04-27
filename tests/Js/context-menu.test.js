import { beforeEach, describe, expect, it } from 'vitest';
import contextMenu from '../../public/js/context-menu.js';

const { contextMenuState, __resetForTests } = contextMenu;

function fakeEvent(x = 50, y = 50) {
    return { clientX: x, clientY: y };
}

beforeEach(() => {
    __resetForTests();
});

describe('contextMenuState', () => {
    it('opens at the click coordinates', () => {
        const menu = contextMenuState();
        menu.openCtx(fakeEvent(120, 80));

        expect(menu.ctxOpen).toBe(true);
        expect(menu.ctxX).toBe(120);
        expect(menu.ctxY).toBe(80);
    });

    it('clamps the menu inside the viewport', () => {
        const menu = contextMenuState();
        // happy-dom defaults to 1024x768; pick coords past the right/bottom edge.
        menu.openCtx(fakeEvent(window.innerWidth + 50, window.innerHeight + 50));

        const margin = 8;
        const menuW = 200;
        const menuH = 80;
        expect(menu.ctxX).toBe(window.innerWidth - menuW - margin);
        expect(menu.ctxY).toBe(window.innerHeight - menuH - margin);
    });

    it('closes the previously open menu when another opens', () => {
        const a = contextMenuState();
        const b = contextMenuState();

        a.openCtx(fakeEvent());
        expect(a.ctxOpen).toBe(true);

        b.openCtx(fakeEvent());
        expect(a.ctxOpen).toBe(false);
        expect(b.ctxOpen).toBe(true);
    });

    it('reopening the same instance is a no-op on others', () => {
        const a = contextMenuState();
        const b = contextMenuState();

        a.openCtx(fakeEvent());
        a.openCtx(fakeEvent());

        expect(a.ctxOpen).toBe(true);
        expect(b.ctxOpen).toBe(false);
    });

    it('closeCtx clears the shared open reference', () => {
        const a = contextMenuState();
        const b = contextMenuState();

        a.openCtx(fakeEvent());
        a.closeCtx();
        // Closing `a` must not later cascade-close `b` when `b` opens.
        b.openCtx(fakeEvent());

        expect(a.ctxOpen).toBe(false);
        expect(b.ctxOpen).toBe(true);
    });
});

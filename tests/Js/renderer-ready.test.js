import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import rendererReady from '../../public/js/renderer-ready.js';

const {
    hasPendingRenderShells,
    hasVisibleRenderBlockers,
    loadRequiredFonts,
    signalWhenSettled,
    install,
} = rendererReady;

describe('renderer readiness', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '';
        delete window.Livewire;
        delete window.__rfaRendererReadyAttached;
        delete window.__rfaRendererReadySent;
        window.nativeRendererReady = vi.fn();
        window.requestAnimationFrame = (callback) => window.setTimeout(callback, 16);
        Object.defineProperty(document, 'fonts', {
            configurable: true,
            value: {
                ready: Promise.resolve(),
                load: vi.fn(() => Promise.resolve()),
            },
        });
        Object.defineProperty(document, 'readyState', {
            configurable: true,
            value: 'complete',
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        delete window.nativeRendererReady;
        delete window.requestAnimationFrame;
        delete window.__rfaRendererReadyAttached;
        delete window.__rfaRendererReadySent;
        delete window.__rfaRendererReadyTimeline;
        delete document.fonts;
        delete document.readyState;
        document.body.innerHTML = '';
    });

    function addPlaceholder(bounds) {
        const placeholder = document.createElement('div');
        placeholder.dataset.rfaRenderBlocker = '';
        placeholder.getBoundingClientRect = vi.fn(() => bounds);
        document.body.appendChild(placeholder);

        return placeholder;
    }

    function addReview(expectedShells) {
        const review = document.createElement('div');
        review.dataset.rfaRenderShells = String(expectedShells);
        document.body.appendChild(review);

        return review;
    }

    it('requests every local font and weight before renderer readiness', async () => {
        await loadRequiredFonts(window);

        expect(document.fonts.load).toHaveBeenCalledTimes(4);
        expect(document.fonts.load).toHaveBeenNthCalledWith(1, '400 1em "Space Grotesk"');
        expect(document.fonts.load).toHaveBeenNthCalledWith(2, '700 1em "Space Grotesk"');
        expect(document.fonts.load).toHaveBeenNthCalledWith(3, '400 1em "JetBrains Mono"');
        expect(document.fonts.load).toHaveBeenNthCalledWith(4, '500 1em "JetBrains Mono"');
    });

    it('fails open when a required font cannot load', async () => {
        document.fonts.load.mockRejectedValueOnce(new Error('font unavailable'));

        const readiness = signalWhenSettled(window, 1000);
        await vi.runAllTimersAsync();

        expect(await readiness).toBe(true);
        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
    });

    it('does not signal when a required font exceeds the renderer timeout', async () => {
        document.fonts.load.mockReturnValueOnce(new Promise(() => {}));

        const readiness = signalWhenSettled(window, 40);
        await vi.runAllTimersAsync();

        expect(await readiness).toBe(false);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();
    });

    it('waits until visible file placeholders are replaced', async () => {
        const placeholder = addPlaceholder({ top: 0, bottom: 40, left: 0, right: 400 });
        const readiness = signalWhenSettled(window, 1000);

        await vi.advanceTimersByTimeAsync(32);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();

        placeholder.remove();
        await vi.runAllTimersAsync();
        await readiness;

        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
    });

    it('waits for first layout before it assesses placeholder visibility', async () => {
        const placeholder = addPlaceholder({ top: 0, bottom: 0, left: 0, right: 0 });
        const readiness = signalWhenSettled(window, 1000);

        window.setTimeout(() => {
            placeholder.getBoundingClientRect.mockReturnValue({ top: 0, bottom: 40, left: 0, right: 400 });
        }, 16);

        await vi.advanceTimersByTimeAsync(64);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();

        placeholder.remove();
        await vi.runAllTimersAsync();
        await readiness;

        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
    });

    it('ignores lazy placeholders outside the viewport', async () => {
        addPlaceholder({ top: -80, bottom: -40, left: 0, right: 400 });

        expect(hasVisibleRenderBlockers(window)).toBe(false);

        const readiness = signalWhenSettled(window, 1000);
        await vi.runAllTimersAsync();
        await readiness;

        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
    });

    it('uses source shell geometry while its placeholder has no layout', () => {
        const review = addReview(1);
        const shell = document.createElement('div');
        shell.dataset.rfaRenderShell = '';
        shell.getBoundingClientRect = vi.fn(() => ({ top: 0, bottom: 40, left: 0, right: 400, width: 400, height: 40 }));
        review.appendChild(shell);

        const placeholder = document.createElement('div');
        placeholder.dataset.rfaRenderBlocker = '';
        placeholder.getBoundingClientRect = vi.fn(() => ({ top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 }));
        shell.appendChild(placeholder);

        expect(hasVisibleRenderBlockers(window)).toBe(true);
    });

    it('waits until every expected file shell has layout geometry', async () => {
        const review = addReview(1);

        expect(hasPendingRenderShells(window)).toBe(true);

        const readiness = signalWhenSettled(window, 1000);
        await vi.advanceTimersByTimeAsync(400);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();

        const shell = document.createElement('div');
        shell.dataset.rfaRenderShell = '';
        shell.getBoundingClientRect = vi.fn(() => ({ width: 400, height: 40 }));
        review.appendChild(shell);

        await vi.runAllTimersAsync();
        await readiness;

        expect(hasPendingRenderShells(window)).toBe(false);
        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
    });

    it('leaves fail-open handling to Electron when a visible placeholder does not settle', async () => {
        addPlaceholder({ top: 0, bottom: 40, left: 0, right: 400 });

        const readiness = signalWhenSettled(window, 40);
        await vi.runAllTimersAsync();
        const signalled = await readiness;

        expect(signalled).toBe(false);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();
    });

    it('signals on the fourth frame: one for layout, two quiet checks, one commit', async () => {
        let frames = 0;
        window.requestAnimationFrame = (callback) => window.setTimeout(() => {
            frames += 1;
            callback();
        }, 16);

        const readiness = signalWhenSettled(window, 1000);

        await vi.advanceTimersByTimeAsync(48);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(16);
        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
        expect(frames).toBe(4);
        expect(await readiness).toBe(true);
    });

    it('restarts the quiet-frame count when the DOM changes between checks', async () => {
        const readiness = signalWhenSettled(window, 1000);

        await vi.advanceTimersByTimeAsync(40);
        document.body.appendChild(document.createElement('div'));

        await vi.advanceTimersByTimeAsync(24);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(16);
        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
        expect(await readiness).toBe(true);
    });

    it('starts once after Livewire finishes initialization', async () => {
        expect(install(window, { timeoutMs: 1000 })).toBe(true);
        expect(install(window, { timeoutMs: 1000 })).toBe(false);

        document.dispatchEvent(new Event('livewire:initialized'));
        await vi.runAllTimersAsync();

        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
    });

    it('announces the renderer-ready signal to the page once', async () => {
        const announced = [];
        document.addEventListener('rfa:renderer-ready', (event) => announced.push(event.detail));

        const first = signalWhenSettled(window, 1000);
        await vi.runAllTimersAsync();
        await first;
        const second = signalWhenSettled(window, 1000);
        await vi.runAllTimersAsync();
        await second;

        expect(announced).toHaveLength(1);
        expect(typeof announced[0].atMs).toBe('number');
        expect(typeof announced[0].windowLoadMs).toBe('number');
        expect(typeof announced[0].fontsReadyMs).toBe('number');
        expect(typeof announced[0].stableMs).toBe('number');
    });
});

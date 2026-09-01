import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import rendererReady from '../../public/js/renderer-ready.js';

const {
    hasPendingFileShells,
    hasVisibleFilePlaceholders,
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
        delete document.documentElement.dataset.rfaRendererReady;
        window.nativeRendererReady = vi.fn();
        window.requestAnimationFrame = (callback) => window.setTimeout(callback, 16);
        Object.defineProperty(document, 'fonts', {
            configurable: true,
            value: { ready: Promise.resolve() },
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
        delete document.documentElement.dataset.rfaRendererReady;
        delete document.fonts;
        delete document.readyState;
        document.body.innerHTML = '';
    });

    function addPlaceholder(bounds) {
        const placeholder = document.createElement('div');
        placeholder.dataset.rfaDiffFilePlaceholder = '';
        placeholder.getBoundingClientRect = vi.fn(() => bounds);
        document.body.appendChild(placeholder);

        return placeholder;
    }

    function addReview(expectedShells) {
        const review = document.createElement('div');
        review.dataset.rfaExpectedFileShells = String(expectedShells);
        document.body.appendChild(review);

        return review;
    }

    it('waits until visible file placeholders are replaced', async () => {
        const placeholder = addPlaceholder({ top: 0, bottom: 40, left: 0, right: 400 });
        const readiness = signalWhenSettled(window, 1000);

        await vi.advanceTimersByTimeAsync(32);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();

        placeholder.remove();
        await vi.runAllTimersAsync();
        await readiness;

        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
        expect(document.documentElement.dataset.rfaRendererReady).toBe('true');
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

        expect(hasVisibleFilePlaceholders(window)).toBe(false);

        const readiness = signalWhenSettled(window, 1000);
        await vi.runAllTimersAsync();
        await readiness;

        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
    });

    it('uses source shell geometry while its placeholder has no layout', () => {
        const review = addReview(1);
        const shell = document.createElement('div');
        shell.dataset.rfaFileShell = '';
        shell.getBoundingClientRect = vi.fn(() => ({ top: 0, bottom: 40, left: 0, right: 400, width: 400, height: 40 }));
        review.appendChild(shell);

        const placeholder = document.createElement('div');
        placeholder.dataset.rfaDiffFilePlaceholder = '';
        placeholder.getBoundingClientRect = vi.fn(() => ({ top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 }));
        shell.appendChild(placeholder);

        expect(hasVisibleFilePlaceholders(window)).toBe(true);
    });

    it('waits until every expected file shell has layout geometry', async () => {
        const review = addReview(1);

        expect(hasPendingFileShells(window)).toBe(true);

        const readiness = signalWhenSettled(window, 1000);
        await vi.advanceTimersByTimeAsync(400);
        expect(window.nativeRendererReady).not.toHaveBeenCalled();

        const shell = document.createElement('div');
        shell.dataset.rfaFileShell = '';
        shell.getBoundingClientRect = vi.fn(() => ({ width: 400, height: 40 }));
        review.appendChild(shell);

        await vi.runAllTimersAsync();
        await readiness;

        expect(hasPendingFileShells(window)).toBe(false);
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

    it('starts once after Livewire finishes initialization', async () => {
        document.documentElement.dataset.rfaRendererReady = 'true';

        expect(install(window, { timeoutMs: 1000 })).toBe(true);
        expect(install(window, { timeoutMs: 1000 })).toBe(false);
        expect(document.documentElement.dataset.rfaRendererReady).toBeUndefined();

        document.dispatchEvent(new Event('livewire:initialized'));
        await vi.runAllTimersAsync();

        expect(window.nativeRendererReady).toHaveBeenCalledOnce();
    });
});

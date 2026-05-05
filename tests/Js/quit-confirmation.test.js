import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import quitConfirmation from '../../public/js/quit-confirmation.js';

const { createQuitConfirmation, install } = quitConfirmation;

describe('createQuitConfirmation', () => {
    let livewire;
    let controller;

    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '';
        livewire = { dispatch: vi.fn() };
        controller = createQuitConfirmation({
            window,
            document,
            livewire,
            thresholdMs: 1500,
            autoDismissMs: 4000,
            repeatSuppressionMs: 500,
        });
        controller.attach();
    });

    afterEach(() => {
        controller.detach();
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('cancels when the shortcut is released before the hold threshold', () => {
        controller.show();
        window.dispatchEvent(new KeyboardEvent('keyup', { key: 'q' }));

        expect(controller.isVisible()).toBe(false);
        expect(livewire.dispatch).not.toHaveBeenCalled();
    });

    it('dispatches quit after the hold threshold and key release', () => {
        controller.show();
        vi.advanceTimersByTime(1500);

        window.dispatchEvent(new KeyboardEvent('keyup', { key: 'Meta' }));

        expect(controller.isVisible()).toBe(false);
        expect(livewire.dispatch).toHaveBeenCalledWith('quit-now');
    });

    it('does not restart the hold threshold when the native accelerator repeats', () => {
        controller.show();
        vi.advanceTimersByTime(1000);

        controller.show();
        vi.advanceTimersByTime(500);

        expect(controller.isArmed()).toBe(true);
    });

    it('ignores queued repeat prompts immediately after an early release', () => {
        controller.show();
        window.dispatchEvent(new KeyboardEvent('keyup', { key: 'q' }));

        controller.show();

        expect(controller.isVisible()).toBe(false);
        expect(livewire.dispatch).not.toHaveBeenCalled();

        vi.advanceTimersByTime(500);
        controller.show();

        expect(controller.isVisible()).toBe(true);
    });

    it('ignores queued repeat prompts immediately after committing quit', () => {
        controller.show();
        vi.advanceTimersByTime(1500);
        window.dispatchEvent(new KeyboardEvent('keyup', { key: 'Meta' }));

        controller.show();

        expect(controller.isVisible()).toBe(false);
        expect(livewire.dispatch).toHaveBeenCalledTimes(1);
    });

    it('dismisses an unarmed prompt after the auto-dismiss timeout', () => {
        controller.show();
        vi.advanceTimersByTime(4000);

        expect(controller.isVisible()).toBe(false);
        expect(livewire.dispatch).not.toHaveBeenCalled();
    });

    it('auto-dismisses even after the prompt is armed', () => {
        controller.show();
        vi.advanceTimersByTime(4000);

        expect(controller.isVisible()).toBe(false);
        expect(livewire.dispatch).not.toHaveBeenCalled();
    });

    it('cancels on Escape while visible', () => {
        controller.show();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(controller.isVisible()).toBe(false);
        expect(livewire.dispatch).not.toHaveBeenCalled();
    });

    it('reattaches the overlay after the body is replaced', () => {
        controller.show();
        const overlay = document.querySelector('[role="alertdialog"]');
        document.body.innerHTML = '';

        controller.show();

        expect(overlay.isConnected).toBe(true);
    });
});

describe('install', () => {
    afterEach(() => {
        window.__quitConfirmation?.detach();
        delete window.__quitConfirmation;
        delete window.__quitConfirmationAttached;
        delete window.__quitConfirmationInstallQueued;
        delete window.Livewire;
    });

    it('installs the window listeners once', () => {
        window.Livewire = { dispatch: vi.fn() };

        expect(install(window)).toBe(true);
        expect(install(window)).toBe(false);
    });

    it('is a no-op when Livewire is not present', () => {
        expect(install(window)).toBe(false);
        expect(window.__quitConfirmationAttached).toBeUndefined();
    });
});

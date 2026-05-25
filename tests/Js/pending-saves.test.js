import { afterEach, describe, expect, it, vi } from 'vitest';
import pendingSaves from '../../public/js/pending-saves.js';

function makeLivewire() {
    const callbacks = [];
    const cleanup = vi.fn((callback) => {
        const index = callbacks.indexOf(callback);

        if (index !== -1) {
            callbacks.splice(index, 1);
        }
    });

    return {
        callbacks,
        cleanup,
        hook: vi.fn((name, callback) => {
            callbacks.push(callback);

            return () => cleanup(callback);
        }),
    };
}

describe('pending saves guard', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('tracks only the owning Livewire component commits', () => {
        const livewire = makeLivewire();
        const updates = [];
        const guard = pendingSaves.createPendingSavesGuard({
            root: window,
            livewire,
            getWireId: () => 'review-1',
            onPendingSavesChanged: (count) => updates.push(count),
        });

        guard.attach();

        const finishers = [];
        livewire.callbacks[0]({
            component: { id: 'other' },
            succeed: (callback) => finishers.push(callback),
            fail: (callback) => finishers.push(callback),
        });

        expect(guard.pendingSaves).toBe(0);

        livewire.callbacks[0]({
            component: { id: 'review-1' },
            succeed: (callback) => finishers.push(callback),
            fail: (callback) => finishers.push(callback),
        });

        expect(guard.pendingSaves).toBe(1);
        expect(updates).toEqual([1]);

        finishers[0]();

        expect(guard.pendingSaves).toBe(0);
        expect(updates).toEqual([1, 0]);

        guard.detach();
    });

    it('detaches the Livewire hook and beforeunload listener', () => {
        const livewire = makeLivewire();
        const addEventListener = vi.spyOn(window, 'addEventListener');
        const removeEventListener = vi.spyOn(window, 'removeEventListener');
        const guard = pendingSaves.createPendingSavesGuard({
            root: window,
            livewire,
            getWireId: () => 'review-1',
        });

        expect(guard.attach()).toBe(true);
        expect(guard.attach()).toBe(false);
        expect(livewire.hook).toHaveBeenCalledTimes(1);

        livewire.callbacks[0]({
            component: { id: 'review-1' },
            succeed: () => {},
            fail: () => {},
        });

        const blocked = new Event('beforeunload', { cancelable: true });
        const preventBlocked = vi.spyOn(blocked, 'preventDefault');

        window.dispatchEvent(blocked);

        expect(preventBlocked).toHaveBeenCalledOnce();

        guard.detach();

        expect(livewire.cleanup).toHaveBeenCalledOnce();
        expect(livewire.callbacks).toHaveLength(0);
        expect(removeEventListener).toHaveBeenCalledWith('beforeunload', addEventListener.mock.calls[0][1]);
        expect(guard.pendingSaves).toBe(0);

        const afterDetach = new Event('beforeunload', { cancelable: true });
        const preventAfterDetach = vi.spyOn(afterDetach, 'preventDefault');

        window.dispatchEvent(afterDetach);

        expect(preventAfterDetach).not.toHaveBeenCalled();
    });
});

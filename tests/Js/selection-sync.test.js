import { describe, expect, it, vi } from 'vitest';
import selectionSync from '../../public/js/selection-sync.js';

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

describe('selection sync guard', () => {
    it('resyncs only after the owning component commit succeeds', () => {
        const livewire = makeLivewire();
        const resync = vi.fn();
        const guard = selectionSync.createSelectionSync({
            livewire,
            getWireId: () => 'review-1',
            onResync: resync,
        });

        expect(guard.attach()).toBe(true);
        expect(guard.attach()).toBe(false);
        expect(livewire.hook).toHaveBeenCalledTimes(1);

        // A different component's commit is ignored entirely.
        livewire.callbacks[0]({
            component: { id: 'other' },
            succeed: () => { throw new Error('should not register a callback for another component'); },
        });
        expect(resync).not.toHaveBeenCalled();

        // The owning component's commit runs the resync only on success.
        const finishers = [];
        livewire.callbacks[0]({
            component: { id: 'review-1' },
            succeed: (callback) => finishers.push(callback),
        });
        expect(resync).not.toHaveBeenCalled();

        finishers[0]();
        expect(resync).toHaveBeenCalledOnce();

        guard.detach();
    });

    it('detaches the Livewire hook', () => {
        const livewire = makeLivewire();
        const guard = selectionSync.createSelectionSync({
            livewire,
            getWireId: () => 'review-1',
            onResync: () => {},
        });

        guard.attach();
        guard.detach();

        expect(livewire.cleanup).toHaveBeenCalledOnce();
        expect(livewire.callbacks).toHaveLength(0);
    });

    it('is inert when Livewire has no hook API', () => {
        const guard = selectionSync.createSelectionSync({
            livewire: {},
            getWireId: () => 'review-1',
            onResync: () => {},
        });

        expect(guard.attach()).toBe(false);
        expect(() => guard.detach()).not.toThrow();
    });
});

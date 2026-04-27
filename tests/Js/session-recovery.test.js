import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import sessionRecovery from '../../public/js/session-recovery.js';

const { shouldRecover, install } = sessionRecovery;

describe('shouldRecover', () => {
    it.each([200, 302, 404, 500])('returns false for non-419 status %i', (status) => {
        expect(
            shouldRecover({ status, now: 1_000_000, lastRecoveryAt: 0 })
        ).toBe(false);
    });

    it.each([
        ['0', 0],
        ['null', null],
        ['undefined', undefined],
    ])('returns true for 419 when lastRecoveryAt is %s', (_label, lastRecoveryAt) => {
        expect(
            shouldRecover({ status: 419, now: 1_000_000, lastRecoveryAt })
        ).toBe(true);
    });

    // The original comparison is `now - lastRecoveryAt < 10_000`, so:
    //   - 9_999 ms ago → recent (suppress reload) → false
    //   - 10_000 ms ago → boundary, NOT recent → false (since 10_000 < 10_000 is false,
    //                     so `shouldRecover` returns true; verify exact behavior)
    //   - 10_001 ms ago → not recent → true
    it('returns false at exactly 9_999 ms ago (still within TTL)', () => {
        expect(
            shouldRecover({ status: 419, now: 10_000, lastRecoveryAt: 1 })
        ).toBe(false);
    });

    it('returns true at exactly 10_000 ms ago (boundary, NOT recent)', () => {
        expect(
            shouldRecover({ status: 419, now: 10_000, lastRecoveryAt: 0 })
        ).toBe(true);
    });

    it('returns true at 10_001 ms ago (outside TTL)', () => {
        expect(
            shouldRecover({ status: 419, now: 10_001, lastRecoveryAt: 0 })
        ).toBe(true);
    });
});

describe('install', () => {
    let originalLocation;
    let reloadSpy;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-27T12:00:00Z'));
        window.sessionStorage.clear();

        reloadSpy = vi.fn();
        originalLocation = window.location;
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...window.location, reload: reloadSpy },
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        window.sessionStorage.clear();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: originalLocation,
        });
        delete window.Livewire;
        delete window.__sessionRecoveryAttached;
    });

    it('registers a Livewire interceptor and is idempotent', () => {
        const interceptRequest = vi.fn();
        window.Livewire = { interceptRequest };

        expect(install(window)).toBe(true);
        expect(interceptRequest).toHaveBeenCalledTimes(1);
        expect(interceptRequest).toHaveBeenCalledWith(expect.any(Function));

        expect(install(window)).toBe(false);
        expect(interceptRequest).toHaveBeenCalledTimes(1);
    });

    it('is a no-op when Livewire is not present', () => {
        expect(install(window)).toBe(false);
        expect(window.__sessionRecoveryAttached).toBeUndefined();
    });

    it('reloads the page on a 419 response and stores the recovery timestamp', () => {
        let capturedInterceptor;
        window.Livewire = {
            interceptRequest: vi.fn((cb) => {
                capturedInterceptor = cb;
            }),
        };

        install(window);

        const onError = vi.fn();
        capturedInterceptor({ onError });
        expect(onError).toHaveBeenCalledTimes(1);

        const errorHandler = onError.mock.calls[0][0];
        const preventDefault = vi.fn();
        errorHandler({ response: { status: 419 }, preventDefault });

        expect(preventDefault).toHaveBeenCalledTimes(1);
        expect(reloadSpy).toHaveBeenCalledTimes(1);
        expect(window.sessionStorage.getItem('__rfa419RecoveryAt')).toBe(
            String(Date.now())
        );
    });

    it('does nothing on non-419 responses', () => {
        let capturedInterceptor;
        window.Livewire = {
            interceptRequest: vi.fn((cb) => {
                capturedInterceptor = cb;
            }),
        };

        install(window);

        const onError = vi.fn();
        capturedInterceptor({ onError });
        const errorHandler = onError.mock.calls[0][0];

        const preventDefault = vi.fn();
        errorHandler({ response: { status: 500 }, preventDefault });

        expect(preventDefault).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
        expect(window.sessionStorage.getItem('__rfa419RecoveryAt')).toBeNull();
    });

    it('falls through on a recurring 419 within the TTL window', () => {
        let capturedInterceptor;
        window.Livewire = {
            interceptRequest: vi.fn((cb) => {
                capturedInterceptor = cb;
            }),
        };

        install(window);

        const onError = vi.fn();
        capturedInterceptor({ onError });
        const errorHandler = onError.mock.calls[0][0];

        // First 419 reloads.
        errorHandler({ response: { status: 419 }, preventDefault: vi.fn() });
        expect(reloadSpy).toHaveBeenCalledTimes(1);

        // 5s later, second 419 should fall through.
        vi.advanceTimersByTime(5_000);
        const preventDefault = vi.fn();
        errorHandler({ response: { status: 419 }, preventDefault });

        expect(preventDefault).not.toHaveBeenCalled();
        expect(reloadSpy).toHaveBeenCalledTimes(1);
    });
});

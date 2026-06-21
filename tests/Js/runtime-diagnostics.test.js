import { afterEach, describe, expect, it, vi } from 'vitest';
import runtimeDiagnostics from '../../public/js/runtime-diagnostics.js';

describe('runtime diagnostics', () => {
    afterEach(() => {
        delete window.__rfaRuntimeDiagnosticsAttached;
        delete window.__rfaRuntimeDiagnosticsLivewireHooked;
        delete window.__rfaLongTasks;
        delete window.__rfaLastDiffActionTiming;
        delete window.__rfaDiffActionTimings;
        delete window.__rfaLastActivity;
        delete window.__rfaFocusState;
        delete window.__rfaLastSmartPollTick;
        delete window.rfaDiagnosticsConfig;
        delete window.Livewire;
        delete document.getAnimations;
        document.body.innerHTML = '';
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('collects heap and DOM counters without query string parsing server-side', () => {
        document.body.innerHTML = `
            <main wire:id="abc">
                <section data-rfa-diff-file data-collapsed="false">
                    <div class="diff-line animate-ping" id="comment-1"></div>
                    <button
                        data-testid="refresh-button"
                        aria-label="Refresh changes"
                        title="Check changes"
                        wire:click="softRefresh"
                        wire:target="softRefresh"
                        data-loading
                    >
                        <span data-testid="sync-spinner" class="inline-flex animate-spin opacity-75"></span>
                    </button>
                </section>
                <section data-rfa-diff-file data-collapsed="true" class="sticky backdrop-blur-sm"></section>
            </main>
        `;
        window.Livewire = {
            find: vi.fn(() => ({
                name: () => 'proxy method, not component name',
                snapshot: { memo: { name: 'pages::review-page' } },
            })),
        };
        window.__rfaLastActivity = { at: Date.now() - 5000, type: 'keydown' };
        window.__rfaFocusState = { at: Date.now() - 2000, visibleAt: Date.now() - 3000 };
        window.__rfaLastSmartPollTick = {
            at: Date.now() - 250,
            source: 'wire:smart-poll:pages::review-page',
            method: 'poll',
            intervalMs: 10000,
            hidden: false,
            focused: true,
        };
        Object.defineProperty(document, 'getAnimations', {
            configurable: true,
            value: vi.fn(() => [{
                animationName: 'spin',
                playState: 'running',
                constructor: { name: 'CSSAnimation' },
                effect: {
                    target: document.querySelector('[data-testid="sync-spinner"]'),
                    getTiming: () => ({ duration: 1000 }),
                },
            }]),
        });

        const sample = runtimeDiagnostics.collectSample(window, 'heartbeat', true);

        expect(sample.reason).toBe('heartbeat');
        expect(sample.includeProcessSnapshot).toBe(true);
        expect(sample.dom.diffFiles).toBe(2);
        expect(sample.dom.expandedDiffFiles).toBe(1);
        expect(sample.dom.livewireComponents).toBe(1);
        expect(sample.dom.diffLines).toBe(1);
        expect(sample.dom.animatedElements).toBe(2);
        expect(sample.dom.animateSpin).toBe(1);
        expect(sample.dom.animatePing).toBe(1);
        expect(sample.dom.backdropBlur).toBe(1);
        expect(sample.dom.sticky).toBe(1);
        expect(sample.animations).toMatchObject({
            activeCount: 1,
            runningCount: 1,
            cssAnimationCount: 1,
            cssTransitionCount: 0,
        });
        expect(sample.animations.classSummary).toContainEqual({ name: 'animate-spin', count: 1 });
        expect(sample.animations.elementGroups[0]).toMatchObject({
            signature: 'span[data-testid="sync-spinner"].animate-spin.opacity-75',
            count: 1,
            runningCount: 1,
            animationNames: ['spin'],
            nearestLivewireName: 'pages::review-page',
            nearestTestId: 'sync-spinner',
            nearestInteractiveSignature: 'button[data-testid="refresh-button"]',
            nearestButtonLabel: 'Refresh changes',
            nearestButtonTitle: 'Check changes',
            nearestLoading: true,
            nearestWireClick: 'softRefresh',
            nearestWireTarget: 'softRefresh',
        });
        expect(sample.animations.elements[0]).toMatchObject({
            signature: 'span[data-testid="sync-spinner"].animate-spin.opacity-75',
            tag: 'span',
            testId: 'sync-spinner',
            classes: ['animate-spin', 'opacity-75'],
            animationNames: ['spin'],
            playStates: ['running'],
            animationCount: 1,
            runningCount: 1,
            maxDurationMs: 1000,
            nearestLivewireName: 'pages::review-page',
            nearestTestId: 'sync-spinner',
            nearestInteractiveSignature: 'button[data-testid="refresh-button"]',
            nearestButtonLabel: 'Refresh changes',
            nearestButtonTitle: 'Check changes',
            nearestLoading: true,
            nearestWireClick: 'softRefresh',
            nearestWireTarget: 'softRefresh',
        });
        expect(sample.activity.idleMs).toBeGreaterThanOrEqual(5000);
        expect(sample.visibility.focusAgeMs).toBeGreaterThanOrEqual(2000);
        expect(sample.poll).toMatchObject({
            source: 'wire:smart-poll:pages::review-page',
            method: 'poll',
            intervalMs: 10000,
        });
    });

    it('converts bytes to megabytes at three decimals', () => {
        expect(runtimeDiagnostics.bytesToMegabytes(1_572_864)).toBe(1.5);
    });

    it('does not take a second process snapshot immediately after forced boot sample', async () => {
        vi.useFakeTimers();

        const payloads = [];
        window.fetch = vi.fn((url, options) => {
            payloads.push(JSON.parse(options.body));

            return Promise.resolve({});
        });
        window.rfaDiagnosticsConfig = {
            enabled: true,
            sampleIntervalMs: 10_000,
            processSampleIntervalMs: 300_000,
        };

        runtimeDiagnostics.install(window);

        expect(payloads[0].reason).toBe('boot');
        expect(payloads[0].includeProcessSnapshot).toBe(true);

        await vi.advanceTimersByTimeAsync(10_000);

        expect(payloads[1].reason).toBe('heartbeat');
        expect(payloads[1].includeProcessSnapshot).toBe(false);
    });

    it('posts diff action timings from browser events', async () => {
        const payloads = [];
        window.fetch = vi.fn((url, options) => {
            payloads.push(JSON.parse(options.body));

            return Promise.resolve({});
        });
        window.rfaDiagnosticsConfig = { enabled: true };

        runtimeDiagnostics.install(window);

        Object.assign(window.__rfaLongTasks, {
            count: 1,
            totalMs: 500,
            maxMs: 500,
            durations: [500],
        });

        document.body.dispatchEvent(new CustomEvent('rfa:diff-action-start', {
            bubbles: true,
            detail: { fileId: 'file-1', action: 'expandContext' },
        }));

        Object.assign(window.__rfaLongTasks, {
            count: 2,
            totalMs: 600,
            maxMs: 500,
            durations: [500, 100],
        });

        window.dispatchEvent(new CustomEvent('rfa:diff-action-completed', {
            detail: {
                fileId: 'file-1',
                action: 'expandContext',
                phpMs: 2200,
                hunkCount: 1,
                diffLineCount: 2247,
                lineContentBytes: 180000,
            },
        }));

        const diffPayload = payloads.find((payload) => payload.reason === 'diff.action');

        expect(diffPayload.timings.diffAction).toMatchObject({
            fileId: 'file-1',
            action: 'expandContext',
            phpMs: 2200,
            hunkCount: 1,
            diffLines: 2247,
            lineContentBytes: 180000,
        });
        expect(diffPayload.timings.longTasksDuringAction).toMatchObject({
            count: 1,
            totalMs: 100,
            maxMs: 100,
        });
        expect(window.__rfaLastDiffActionTiming.diffLines).toBe(2247);
        expect(window.__rfaDiffActionTimings.expandContext.diffLines).toBe(2247);
    });

    it('attributes Livewire commits to component calls and recent poll ticks', async () => {
        const payloads = [];
        let commitHook;
        let succeedCallback;

        window.fetch = vi.fn((url, options) => {
            payloads.push(JSON.parse(options.body));

            return Promise.resolve({});
        });
        window.Livewire = {
            hook: vi.fn((name, callback) => {
                commitHook = callback;
            }),
        };
        window.rfaDiagnosticsConfig = {
            enabled: true,
            commitSampleThrottleMs: 0,
        };
        window.__rfaLastSmartPollTick = {
            at: Date.now() - 75,
            source: 'wire:smart-poll:pages::review-page',
            method: 'poll',
            intervalMs: 10000,
            hidden: false,
            focused: true,
        };

        runtimeDiagnostics.install(window);
        document.dispatchEvent(new Event('livewire:init'));

        commitHook({
            component: { id: 'abc123', name: 'pages::review-page' },
            commit: {
                updates: { fileFilter: 'foo', 'nested.value': true },
                calls: [{ method: 'poll' }, { method: 'softRefresh' }],
            },
            succeed: (callback) => {
                succeedCallback = callback;
            },
            fail: vi.fn(),
        });

        succeedCallback();

        const commitPayload = payloads.find((payload) => payload.reason === 'livewire.commit');

        expect(commitPayload.timings.livewireCommit).toMatchObject({
            status: 'succeeded',
            componentId: 'abc123',
            componentName: 'pages::review-page',
            callCount: 2,
            calls: ['poll', 'softRefresh'],
            updateCount: 2,
            updateKeys: ['fileFilter', 'nested.value'],
            pollSource: 'wire:smart-poll:pages::review-page',
            pollMethod: 'poll',
        });
        expect(commitPayload.timings.livewireCommit.pollAgeMs).toBeGreaterThanOrEqual(75);
    });
});

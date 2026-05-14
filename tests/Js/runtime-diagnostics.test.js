import { afterEach, describe, expect, it, vi } from 'vitest';
import runtimeDiagnostics from '../../public/js/runtime-diagnostics.js';

describe('runtime diagnostics', () => {
    afterEach(() => {
        delete window.__rfaRuntimeDiagnosticsAttached;
        delete window.__rfaRuntimeDiagnosticsLivewireHooked;
        delete window.rfaDiagnosticsConfig;
        document.body.innerHTML = '';
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('collects heap and DOM counters without query string parsing server-side', () => {
        document.body.innerHTML = `
            <main wire:id="abc">
                <section data-rfa-diff-file data-collapsed="false">
                    <div class="diff-line" id="comment-1"></div>
                </section>
                <section data-rfa-diff-file data-collapsed="true"></section>
            </main>
        `;

        const sample = runtimeDiagnostics.collectSample(window, 'heartbeat', true);

        expect(sample.reason).toBe('heartbeat');
        expect(sample.includeProcessSnapshot).toBe(true);
        expect(sample.dom.diffFiles).toBe(2);
        expect(sample.dom.expandedDiffFiles).toBe(1);
        expect(sample.dom.livewireComponents).toBe(1);
        expect(sample.dom.diffLines).toBe(1);
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
});

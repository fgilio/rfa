import { EventEmitter } from 'node:events';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const patchSource = readFileSync('scripts/patch-nativephp.php', 'utf8');
const functionSource = patchSource.slice(
    patchSource.indexOf('function rfaPatchRendererReadyWindow'),
    patchSource.indexOf('function rfaPatchServerOptimize'),
);
const readinessBlock = functionSource.match(/\$replace = <<<'JS'\n([\s\S]*?)\nJS;/)?.[1];

if (!readinessBlock) throw new Error('Unable to find the generated window readiness block');

const installReadiness = new Function(
    'window',
    'state',
    'id',
    'setTimeout',
    'clearTimeout',
    `${readinessBlock}\nreturn { phase: () => rfaPresentationPhase };`,
);

function createHarness({ id = 'main', visible = false, invalidateError = null } = {}) {
    let frameCallback = null;
    const webContents = new EventEmitter();
    webContents.beginFrameSubscription = vi.fn((_, callback) => {
        frameCallback = callback;
    });
    webContents.endFrameSubscription = vi.fn();
    webContents.invalidate = invalidateError
        ? vi.fn(() => { throw invalidateError; })
        : vi.fn();

    const browserWindow = new EventEmitter();
    const emit = vi.spyOn(browserWindow, 'emit');
    browserWindow.webContents = webContents;
    browserWindow.setOpacity = vi.fn();
    browserWindow.show = vi.fn(() => { visible = true; });
    browserWindow.focus = vi.fn();
    browserWindow.isVisible = vi.fn(() => visible);

    const state = { noFocusOnRestart: false };
    const readiness = installReadiness(browserWindow, state, id, setTimeout, clearTimeout);

    return {
        browserWindow,
        emit,
        frame: () => frameCallback?.(),
        readiness,
        state,
        webContents,
    };
}

function presentationEvents(harness) {
    return harness.emit.mock.calls.filter(([event]) => event === 'rfa:presented');
}

describe('generated native window presentation', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('presents the main window once after renderer IPC and one submitted frame', () => {
        const harness = createHarness();

        expect(harness.readiness.phase()).toBe('waiting-renderer');

        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');
        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');

        expect(harness.readiness.phase()).toBe('waiting-frame');
        expect(harness.webContents.beginFrameSubscription).toHaveBeenCalledOnce();

        harness.frame();
        harness.frame();

        expect(harness.readiness.phase()).toBe('presented');
        expect(harness.browserWindow.setOpacity).toHaveBeenCalledOnce();
        expect(harness.browserWindow.show).toHaveBeenCalledOnce();
        expect(harness.webContents.endFrameSubscription).toHaveBeenCalledOnce();
        expect(presentationEvents(harness)).toHaveLength(1);
    });

    it('fails open once when renderer readiness times out', async () => {
        const harness = createHarness();

        await vi.advanceTimersByTimeAsync(5000);
        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');

        expect(harness.readiness.phase()).toBe('presented');
        expect(harness.browserWindow.show).toHaveBeenCalledOnce();
        expect(harness.webContents.beginFrameSubscription).not.toHaveBeenCalled();
        expect(presentationEvents(harness)).toHaveLength(1);
    });

    it('presents and cleans up when frame invalidation fails', () => {
        const harness = createHarness({ invalidateError: new Error('frame unavailable') });

        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');

        expect(harness.readiness.phase()).toBe('presented');
        expect(harness.webContents.endFrameSubscription).toHaveBeenCalledOnce();
        expect(harness.browserWindow.show).toHaveBeenCalledOnce();
        expect(presentationEvents(harness)).toHaveLength(1);
    });

    it('does not present a window that closes while waiting', async () => {
        const harness = createHarness();

        harness.browserWindow.emit('closed');
        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');
        harness.browserWindow.rfaRequestShow(true);
        await vi.advanceTimersByTimeAsync(5000);

        expect(harness.readiness.phase()).toBe('closed');
        expect(harness.webContents.beginFrameSubscription).not.toHaveBeenCalled();
        expect(harness.browserWindow.show).not.toHaveBeenCalled();
        expect(presentationEvents(harness)).toHaveLength(0);
    });

    it('keeps queued focus separate from later show requests', () => {
        const harness = createHarness();
        harness.browserWindow.rfaRequestShow(true);

        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');
        harness.frame();

        expect(harness.browserWindow.focus).toHaveBeenCalledOnce();
        expect(presentationEvents(harness)).toHaveLength(1);

        harness.browserWindow.rfaRequestShow();
        expect(harness.browserWindow.focus).toHaveBeenCalledOnce();

        harness.browserWindow.rfaRequestShow(true);
        expect(harness.browserWindow.focus).toHaveBeenCalledTimes(2);
        expect(presentationEvents(harness)).toHaveLength(1);
    });

    it('restores main-window opacity without focusing a visible restart window', () => {
        const harness = createHarness({ visible: true });
        harness.state.noFocusOnRestart = true;

        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');
        harness.frame();

        expect(harness.browserWindow.setOpacity).toHaveBeenCalledWith(1);
        expect(harness.browserWindow.show).not.toHaveBeenCalled();
        expect(harness.browserWindow.focus).not.toHaveBeenCalled();
        expect(presentationEvents(harness)).toHaveLength(1);
    });

    it('presents other windows after first paint without renderer IPC', () => {
        const harness = createHarness({ id: 'secondary' });
        harness.browserWindow.rfaRequestShow(true);

        harness.browserWindow.emit('ready-to-show');

        expect(harness.readiness.phase()).toBe('presented');
        expect(harness.browserWindow.show).toHaveBeenCalledOnce();
        expect(harness.browserWindow.focus).toHaveBeenCalledOnce();
        expect(harness.browserWindow.setOpacity).not.toHaveBeenCalled();
        expect(presentationEvents(harness)).toHaveLength(0);
    });
});

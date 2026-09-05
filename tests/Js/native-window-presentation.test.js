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

function createHarness({ id = 'main', visible = false } = {}) {
    const webContents = new EventEmitter();
    const removeListener = vi.spyOn(webContents, 'removeListener');

    const browserWindow = new EventEmitter();
    const emit = vi.spyOn(browserWindow, 'emit');
    let destroyed = false;
    browserWindow.webContents = webContents;
    browserWindow.setOpacity = vi.fn();
    browserWindow.show = vi.fn(() => { visible = true; });
    browserWindow.focus = vi.fn();
    browserWindow.isVisible = vi.fn(() => visible);
    browserWindow.isDestroyed = vi.fn(() => destroyed);
    browserWindow.destroy = () => {
        destroyed = true;
        Object.defineProperty(browserWindow, 'webContents', {
            get() { throw new TypeError('Object has been destroyed'); },
        });
    };

    const state = { noFocusOnRestart: false };
    const readiness = installReadiness(browserWindow, state, id, setTimeout, clearTimeout);

    return {
        browserWindow,
        emit,
        readiness,
        removeListener,
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

    it('presents the main window once, as soon as the renderer reports readiness', () => {
        const harness = createHarness();

        expect(harness.readiness.phase()).toBe('waiting-renderer');
        expect(harness.browserWindow.show).not.toHaveBeenCalled();

        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');
        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');

        expect(harness.readiness.phase()).toBe('presented');
        expect(harness.browserWindow.setOpacity).toHaveBeenCalledOnce();
        expect(harness.browserWindow.setOpacity).toHaveBeenCalledWith(1);
        expect(harness.browserWindow.show).toHaveBeenCalledOnce();
        expect(harness.removeListener).toHaveBeenCalledWith('ipc-message', expect.any(Function));
        expect(presentationEvents(harness)).toHaveLength(1);
    });

    it('ignores unrelated renderer messages', () => {
        const harness = createHarness();

        harness.webContents.emit('ipc-message', {}, 'rfa:something-else');

        expect(harness.readiness.phase()).toBe('waiting-renderer');
        expect(harness.browserWindow.show).not.toHaveBeenCalled();
    });

    it('fails open once when renderer readiness times out', async () => {
        const harness = createHarness();

        await vi.advanceTimersByTimeAsync(5000);
        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');

        expect(harness.readiness.phase()).toBe('presented');
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
        expect(harness.browserWindow.show).not.toHaveBeenCalled();
        expect(presentationEvents(harness)).toHaveLength(0);
    });

    it('survives a window whose webContents is already destroyed when it closes', async () => {
        const harness = createHarness();

        harness.browserWindow.destroy();

        expect(() => harness.browserWindow.emit('closed')).not.toThrow();
        expect(harness.readiness.phase()).toBe('closed');
        expect(harness.removeListener).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(5000);

        expect(harness.browserWindow.show).not.toHaveBeenCalled();
    });

    it('keeps queued focus separate from later show requests', () => {
        const harness = createHarness();
        harness.browserWindow.rfaRequestShow(true);

        harness.webContents.emit('ipc-message', {}, 'rfa:renderer-ready');

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

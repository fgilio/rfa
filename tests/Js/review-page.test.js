import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import reviewPage from '../../public/js/review-page.js';

const { computeRemoteMenu, createReviewPage, createChangePoller, install } = reviewPage;

// A roomy viewport so the clamp never trims the requested click position;
// clamping itself is covered by its own test.
const VIEWPORT = { width: 2000, height: 2000 };

const config = {
    projectSlug: 'my-project',
    projectBranch: 'main',
    diffFrom: 'HEAD',
    diffTo: null,
};

describe('computeRemoteMenu', () => {
    it('uses the event projectSlug for a direct link, falling back to config', () => {
        const withSlug = computeRemoteMenu(
            { target: 'direct', type: 'repo', params: { a: 1 }, label: 'repository', projectSlug: 'other', clientX: 10, clientY: 20 },
            config,
            VIEWPORT,
        );
        expect(withSlug).toMatchObject({ open: true, type: 'repo', params: { a: 1 }, label: 'repository', projectSlug: 'other', disabled: false });

        const withoutSlug = computeRemoteMenu(
            { target: 'direct', type: 'repo', clientX: 10, clientY: 20 },
            config,
            VIEWPORT,
        );
        expect(withoutSlug).toMatchObject({ projectSlug: 'my-project', params: {}, label: 'on remote' });
    });

    it('builds a file link against the new-side ref', () => {
        const menu = computeRemoteMenu(
            { target: 'file', filePath: 'src/Foo.php', status: 'modified', clientX: 0, clientY: 0 },
            config,
            VIEWPORT,
        );
        expect(menu.type).toBe('file');
        expect(menu.params).toEqual({ ref: 'main', path: 'src/Foo.php' });
        expect(menu.label).toBe('file');
        expect(menu.disabled).toBe(false);
    });

    it('prefers diffTo as the new-side ref in commit/range mode', () => {
        const menu = computeRemoteMenu(
            { target: 'file', filePath: 'src/Foo.php', status: 'modified', clientX: 0, clientY: 0 },
            { ...config, diffFrom: 'abc1230', diffTo: 'abc1234' },
            VIEWPORT,
        );
        expect(menu.params.ref).toBe('abc1234');
    });

    it('labels a single line and a line range', () => {
        const single = computeRemoteMenu(
            { target: 'line', side: 'new', filePath: 'a.php', status: 'modified', start: 5, end: 5, clientX: 0, clientY: 0 },
            config,
            VIEWPORT,
        );
        expect(single.label).toBe('line 5');

        const range = computeRemoteMenu(
            { target: 'line', side: 'new', filePath: 'a.php', status: 'modified', start: 5, end: 9, clientX: 0, clientY: 0 },
            config,
            VIEWPORT,
        );
        expect(range.label).toBe('lines 5-9');
    });

    it('points an old-side line link at the old ref and old path', () => {
        const menu = computeRemoteMenu(
            { target: 'line', side: 'old', filePath: 'new/name.php', oldPath: 'old/name.php', status: 'modified', start: 3, end: 3, clientX: 0, clientY: 0 },
            { ...config, diffFrom: 'abc1230', diffTo: 'abc1234' },
            VIEWPORT,
        );
        expect(menu.params.ref).toBe('abc1230');
        expect(menu.params.path).toBe('old/name.php');
    });

    it('disables an added file in pure working-tree mode (not pushed yet)', () => {
        const menu = computeRemoteMenu(
            { target: 'file', filePath: 'new.php', status: 'added', clientX: 0, clientY: 0 },
            config,
            VIEWPORT,
        );
        expect(menu.disabled).toBe(true);
        expect(menu.disabledReason).toBe('File not pushed to remote yet');
    });

    it('disables a deleted file in commit/range mode (removed at this commit)', () => {
        const menu = computeRemoteMenu(
            { target: 'file', filePath: 'gone.php', status: 'deleted', clientX: 0, clientY: 0 },
            { ...config, diffTo: 'abc1234' },
            VIEWPORT,
        );
        expect(menu.disabled).toBe(true);
        expect(menu.disabledReason).toBe('File was removed at this commit');
    });

    it('leaves an old-side line link to a deleted file enabled', () => {
        // usesNewSideRef is false for an old-side line, so the new-side break
        // rule never disables it.
        const menu = computeRemoteMenu(
            { target: 'line', side: 'old', filePath: 'gone.php', status: 'deleted', start: 1, end: 1, clientX: 0, clientY: 0 },
            { ...config, diffTo: 'abc1234' },
            VIEWPORT,
        );
        expect(menu.disabled).toBe(false);
    });

    it('clamps the menu position into the viewport', () => {
        const menu = computeRemoteMenu(
            { target: 'direct', type: 'repo', clientX: 5000, clientY: 5000 },
            config,
            { width: 500, height: 400 },
        );
        // 500 - 220 (menuW) - 8 (margin) = 272 ; 400 - 80 (menuH) - 8 = 312
        expect(menu.x).toBe(272);
        expect(menu.y).toBe(312);
    });
});

describe('createChangePoller', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    function respondWith(...payloads) {
        const fn = vi.fn();
        payloads.forEach((payload) => {
            fn.mockResolvedValueOnce({ json: async () => payload });
        });
        vi.stubGlobal('fetch', fn);
        return fn;
    }

    function deferredResponse() {
        let resolve;
        const response = new Promise((resolvePromise) => {
            resolve = (payload) => resolvePromise({ json: async () => payload });
        });

        return { response, resolve };
    }

    it('baselines the fingerprint on the first check without flagging changes', async () => {
        respondWith({ fingerprint: 'fp-1', count: 0 });
        const poller = createChangePoller({ projectId: 5 });

        await poller.check();

        expect(poller.baselineFingerprint).toBe('fp-1');
        expect(poller.hasChanges).toBe(false);
        expect(poller.pendingChangeCount).toBeNull();
    });

    it('flags changes and records the count when the fingerprint moves', async () => {
        const fetchFn = respondWith({ fingerprint: 'fp-1', count: 0 }, { fingerprint: 'fp-2', count: 3 });
        const poller = createChangePoller({ projectId: 5 });

        await poller.check();
        await poller.check();

        expect(fetchFn).toHaveBeenCalledWith('/api/changes/5');
        expect(poller.hasChanges).toBe(true);
        expect(poller.pendingChangeCount).toBe(3);
    });

    it('swallows fetch failures and leaves state untouched', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        const poller = createChangePoller({ projectId: 5 });

        await poller.check();

        expect(poller.baselineFingerprint).toBeNull();
        expect(poller.hasChanges).toBe(false);
        expect(poller.pendingChangeCount).toBeNull();
    });

    it.each([
        new Error('offline'),
        new DOMException('aborted', 'AbortError'),
    ])('preserves an established baseline when a request fails', async (error) => {
        const fetchFn = respondWith({ fingerprint: 'fp-1', count: 0 });
        fetchFn.mockRejectedValueOnce(error);
        const poller = createChangePoller({ projectId: 5 });

        await poller.check();
        await poller.check();

        expect(poller.baselineFingerprint).toBe('fp-1');
        expect(poller.pendingChangeCount).toBeNull();
    });

    it('reset() re-baselines and re-checks', async () => {
        respondWith({ fingerprint: 'fp-1', count: 0 }, { fingerprint: 'fp-2', count: 2 }, { fingerprint: 'fp-3', count: 0 });
        const poller = createChangePoller({ projectId: 5 });

        await poller.check();
        await poller.check();
        expect(poller.hasChanges).toBe(true);

        await poller.reset();

        expect(poller.hasChanges).toBe(false);
        expect(poller.pendingChangeCount).toBeNull();
        expect(poller.baselineFingerprint).toBe('fp-3');
    });

    it('ignores a response from before reset establishes a new baseline', async () => {
        const staleResponse = deferredResponse();
        const currentResponse = deferredResponse();
        const fetchFn = respondWith({ fingerprint: 'fp-1', count: 0 });
        fetchFn
            .mockReturnValueOnce(staleResponse.response)
            .mockReturnValueOnce(currentResponse.response);
        const poller = createChangePoller({ projectId: 5 });

        await poller.check();
        const staleCheck = poller.check();
        const resetCheck = poller.reset();

        currentResponse.resolve({ fingerprint: 'fp-3', count: 0 });
        await resetCheck;
        staleResponse.resolve({ fingerprint: 'fp-2', count: 2 });
        await staleCheck;

        expect(poller.baselineFingerprint).toBe('fp-3');
        expect(poller.pendingChangeCount).toBeNull();
    });

    it('keeps the newest check when responses arrive out of order', async () => {
        const olderResponse = deferredResponse();
        const newerResponse = deferredResponse();
        const fetchFn = respondWith({ fingerprint: 'fp-1', count: 0 });
        fetchFn
            .mockReturnValueOnce(olderResponse.response)
            .mockReturnValueOnce(newerResponse.response);
        const poller = createChangePoller({ projectId: 5 });

        await poller.check();
        const olderCheck = poller.check();
        const newerCheck = poller.check();

        newerResponse.resolve({ fingerprint: 'fp-3', count: 3 });
        await newerCheck;
        olderResponse.resolve({ fingerprint: 'fp-2', count: 2 });
        await olderCheck;

        expect(poller.baselineFingerprint).toBe('fp-1');
        expect(poller.pendingChangeCount).toBe(3);
    });

    it('ignores pending work after destruction', async () => {
        const pendingResponse = deferredResponse();
        const fetchFn = respondWith({ fingerprint: 'fp-1', count: 0 });
        fetchFn.mockReturnValueOnce(pendingResponse.response);
        const poller = createChangePoller({ projectId: 5 });

        await poller.check();
        const pendingCheck = poller.check();
        poller.destroy();

        pendingResponse.resolve({ fingerprint: 'fp-2', count: 2 });
        await pendingCheck;

        expect(poller.baselineFingerprint).toBe('fp-1');
        expect(poller.pendingChangeCount).toBeNull();
    });

    it('renders the changed-file count in the tooltip', () => {
        const poller = createChangePoller({ projectId: 5, refreshCombo: '⌘R', hardReloadCombo: '⌘⇧R' });
        expect(poller.tooltip).toBe('Refresh · ⌘R · ⌘⇧R to hard reload');

        poller.pendingChangeCount = 1;
        expect(poller.tooltip).toBe('1 file changed externally - click to refresh');

        poller.pendingChangeCount = 4;
        expect(poller.tooltip).toBe('4 files changed externally - click to refresh');
    });

    it('registers refresh shortcuts on init and unregisters them on destroy (browser build)', () => {
        const stopPoll = vi.fn();
        const shortcuts = { register: vi.fn(), unregister: vi.fn() };
        window.smartPoll = { startSmartPoll: () => stopPoll, isFocused: () => true };
        respondWith({ fingerprint: 'fp-1', count: 0 });

        const poller = createChangePoller({ projectId: 5, keymapEnabled: true });
        poller.$store = { shortcuts };

        poller.init();
        expect(shortcuts.register).toHaveBeenCalledWith('app.refresh', expect.any(Function));
        expect(shortcuts.register).toHaveBeenCalledWith('app.hard-reload', expect.any(Function));

        poller.destroy();
        expect(shortcuts.unregister).toHaveBeenCalledWith('app.refresh');
        expect(shortcuts.unregister).toHaveBeenCalledWith('app.hard-reload');
        expect(stopPoll).toHaveBeenCalled();
    });

    it('leaves shortcuts alone in the native build where keymap is disabled', () => {
        const shortcuts = { register: vi.fn(), unregister: vi.fn() };
        window.smartPoll = { startSmartPoll: () => vi.fn(), isFocused: () => true };
        respondWith({ fingerprint: 'fp-1', count: 0 });

        const poller = createChangePoller({ projectId: 5, keymapEnabled: false });
        poller.$store = { shortcuts };

        poller.init();
        poller.destroy();

        expect(shortcuts.register).not.toHaveBeenCalled();
        expect(shortcuts.unregister).not.toHaveBeenCalled();
    });
});

describe('install', () => {
    afterEach(() => {
        delete window.Alpine;
    });

    it('registers the latest review-page Alpine factories every time it loads', () => {
        const data = vi.fn();
        window.Alpine = { data };

        expect(install(window)).toBe(true);
        expect(install(window)).toBe(true);

        expect(data).toHaveBeenCalledTimes(4);
        expect(data).toHaveBeenNthCalledWith(1, 'reviewPage', expect.any(Function));
        expect(data).toHaveBeenNthCalledWith(2, 'reviewChangePoller', expect.any(Function));
        expect(data).toHaveBeenNthCalledWith(3, 'reviewPage', expect.any(Function));
        expect(data).toHaveBeenNthCalledWith(4, 'reviewChangePoller', expect.any(Function));
    });
});

describe('createReviewPage path helpers', () => {
    afterEach(() => {
        delete window.__rfaReviewedActionQueue;
    });

    it('splits a path into directory and basename', () => {
        const page = createReviewPage(config);
        expect(page.pathDir('src/app/Foo.php')).toBe('src/app/');
        expect(page.pathBase('src/app/Foo.php')).toBe('Foo.php');
        expect(page.pathDir('Foo.php')).toBe('');
        expect(page.pathBase('Foo.php')).toBe('Foo.php');
        expect(page.pathDir('')).toBe('');
    });

    it('joins the repo path, trimming a trailing slash', () => {
        expect(createReviewPage({ repoPath: '/tmp/repo/' }).buildFullPath('a/b.php')).toBe('/tmp/repo/a/b.php');
        expect(createReviewPage({ repoPath: '/tmp/repo' }).buildFullPath('a/b.php')).toBe('/tmp/repo/a/b.php');
        expect(createReviewPage({ repoPath: '' }).buildFullPath('a/b.php')).toBe('a/b.php');
    });

    it('seeds activeFile from config', () => {
        expect(createReviewPage({ activeFile: 'file-7' }).activeFile).toBe('file-7');
        expect(createReviewPage({}).activeFile).toBeNull();
    });

    it('focuses the requested file without persisting the selection again', () => {
        const page = createReviewPage({ initialFocusFileId: 'file-7' });
        page.$nextTick = (callback) => callback();
        page.scrollToFile = vi.fn();

        page.focusInitialFile();

        expect(page.scrollToFile).toHaveBeenCalledWith('file-7', false);
    });

    it('does not focus a file during normal project entry', () => {
        const page = createReviewPage({});
        page.$nextTick = vi.fn();

        page.focusInitialFile();

        expect(page.$nextTick).not.toHaveBeenCalled();
    });

    it('removes the focused file from the current URL', () => {
        window.history.replaceState({}, '', '/p/my-project?file=src%2FFoo.php&keep=yes');
        const page = createReviewPage({});

        page.clearFocusedFileUrl();

        expect(window.location.pathname).toBe('/p/my-project');
        expect(window.location.search).toBe('?keep=yes');
    });

    it('serializes reviewed actions so rapid toggles use settled Livewire state', async () => {
        let releaseFirst;
        const first = new Promise(resolve => {
            releaseFirst = resolve;
        });
        const calls = [];
        const page = createReviewPage({});
        page.$wire = {
            toggleReviewed: vi.fn((path) => {
                calls.push(path);

                return path === 'src/Foo.php' ? first : Promise.resolve();
            }),
        };

        const firstToggle = page.toggleReviewed('src/Foo.php');
        const secondToggle = page.toggleReviewed('src/Bar.php');

        await Promise.resolve();
        await Promise.resolve();

        expect(calls).toEqual(['src/Foo.php']);

        releaseFirst();
        await firstToggle;
        await secondToggle;

        expect(calls).toEqual(['src/Foo.php', 'src/Bar.php']);
    });

    it('keeps reviewed actions serialized across remounted page instances', async () => {
        let releaseFirst;
        const first = new Promise(resolve => {
            releaseFirst = resolve;
        });
        const calls = [];
        const firstPage = createReviewPage({});
        const remountedPage = createReviewPage({});
        firstPage.$wire = {
            toggleReviewed: vi.fn((path) => {
                calls.push(path);

                return first;
            }),
        };
        remountedPage.$wire = {
            toggleReviewed: vi.fn((path) => {
                calls.push(path);

                return Promise.resolve();
            }),
        };

        const firstToggle = firstPage.toggleReviewed('src/Foo.php');
        const secondToggle = remountedPage.toggleReviewed('src/Bar.php');

        await Promise.resolve();
        await Promise.resolve();

        expect(calls).toEqual(['src/Foo.php']);

        releaseFirst();
        await firstToggle;
        await secondToggle;

        expect(calls).toEqual(['src/Foo.php', 'src/Bar.php']);
    });

    it('ignores reviewed toggle events without a file path', async () => {
        const page = createReviewPage({});
        page.$wire = { toggleReviewed: vi.fn() };

        await page.toggleReviewed(undefined);

        expect(page.$wire.toggleReviewed).not.toHaveBeenCalled();
    });
});

describe('review.comment-selection shortcut', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    function pageWithShortcut() {
        const handlers = {};
        const page = createReviewPage({});
        page.registeredShortcutIds = [];
        page.$store = { shortcuts: { register: (id, handler) => { handlers[id] = handler; } }, settings: {} };
        page.$dispatch = vi.fn();
        page.$refs = {};
        page.registerShortcuts();
        return { page, fire: handlers['review.comment-selection'] };
    }

    function stubSelection({ startSelector, collapsed = false }) {
        const node = startSelector ? document.querySelector(startSelector).firstChild : null;
        vi.spyOn(window, 'getSelection').mockReturnValue({
            rangeCount: node ? 1 : 0,
            isCollapsed: collapsed,
            getRangeAt: () => ({ startContainer: node }),
        });
    }

    it('targets the file that owns the selection by id', () => {
        document.body.innerHTML = `
            <div data-file-id="file-9">
                <div class="diff-line" data-line-new="3"><div class="diff-cell-content">hello</div></div>
            </div>`;
        const { page, fire } = pageWithShortcut();
        stubSelection({ startSelector: '[data-file-id="file-9"] .diff-cell-content' });

        fire();

        expect(page.$dispatch).toHaveBeenCalledWith('rfa-comment-selection', { fileId: 'file-9' });
    });

    it('does nothing when the selection is collapsed', () => {
        document.body.innerHTML = '<div data-file-id="file-9"><div class="diff-cell-content">hi</div></div>';
        const { page, fire } = pageWithShortcut();
        stubSelection({ startSelector: '.diff-cell-content', collapsed: true });

        fire();

        expect(page.$dispatch).not.toHaveBeenCalled();
    });

    it('does nothing when the selection is not inside a file', () => {
        document.body.innerHTML = '<div class="sidebar">unrelated text</div>';
        const { page, fire } = pageWithShortcut();
        stubSelection({ startSelector: '.sidebar' });

        fire();

        expect(page.$dispatch).not.toHaveBeenCalled();
    });
});

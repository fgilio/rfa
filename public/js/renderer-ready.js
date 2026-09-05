// Coordinates RFA's final renderer frame with the NativePHP main window.
(function (root, factory) {
    const api = factory();

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.rfaRendererReady = api;
        api.install(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const DEFAULT_TIMEOUT_MS = 4000;
    const POLL_INTERVAL_MS = 100;
    const REQUIRED_STABLE_FRAMES = 3;
    const REQUIRED_FONTS = [
        '400 1em "Space Grotesk"',
        '700 1em "Space Grotesk"',
        '400 1em "JetBrains Mono"',
        '500 1em "JetBrains Mono"',
    ];

    function waitForWindowLoad(root) {
        if (root.document.readyState === 'complete') return Promise.resolve();

        return new Promise((resolve) => {
            root.addEventListener('load', resolve, { once: true });
        });
    }

    function nextFrame(root) {
        return new Promise((resolve) => {
            if (typeof root.requestAnimationFrame === 'function') {
                root.requestAnimationFrame(() => resolve());

                return;
            }

            root.setTimeout(resolve, 16);
        });
    }

    function loadRequiredFonts(root) {
        const fonts = root.document.fonts;

        if (!fonts) return null;

        const requests = typeof fonts.load === 'function'
            ? REQUIRED_FONTS.map((font) => fonts.load(font))
            : [];

        return Promise.all(requests).then(() => fonts.ready);
    }

    function isInViewport(element, root) {
        const bounds = element.getBoundingClientRect();

        return bounds.top < root.innerHeight
            && bounds.bottom > 0
            && bounds.left < root.innerWidth
            && bounds.right > 0;
    }

    function hasVisibleRenderBlockers(root) {
        return Array.from(root.document.querySelectorAll('[data-rfa-render-blocker]'))
            .some((blocker) => {
                const renderShell = blocker.closest('[data-rfa-render-shell]');

                return isInViewport(renderShell || blocker, root);
            });
    }

    function hasPendingRenderShells(root) {
        const renderRoot = root.document.querySelector('[data-rfa-render-shells]');

        if (!renderRoot) return false;

        const expected = Number(renderRoot.dataset.rfaRenderShells || 0);

        if (expected === 0) return false;

        const shells = Array.from(renderRoot.querySelectorAll('[data-rfa-render-shell]'));

        return shells.length < expected || shells.some((shell) => {
            const bounds = shell.getBoundingClientRect();

            return bounds.width === 0 || bounds.height === 0;
        });
    }

    async function settleRenderer(root, timeoutMs = DEFAULT_TIMEOUT_MS) {
        const startedAt = Date.now();
        const fonts = loadRequiredFonts(root);
        const fontReadiness = fonts
            ? (async () => {
                try {
                    await fonts;
                } catch (_) {
                    // Font errors must not strand the startup screen.
                }
            })()
            : null;
        let fontsReady = fonts === null;

        // Livewire initializes while its script is still executing. Window load
        // is the first point where Chromium can expose final shell geometry.
        await waitForWindowLoad(root);

        // Let Alpine's initialization work reach layout before reading geometry.
        await nextFrame(root);
        await nextFrame(root);

        return new Promise((resolve) => {
            let stableFrames = 0;
            let checkScheduled = false;
            let finished = false;
            let pollId = null;
            const observer = typeof root.MutationObserver === 'function'
                ? new root.MutationObserver(() => {
                    stableFrames = 0;
                    scheduleCheck();
                })
                : null;
            const isSettled = () => fontsReady
                && !hasPendingRenderShells(root)
                && !hasVisibleRenderBlockers(root);
            const finish = (result) => {
                if (finished) return;

                finished = true;
                root.clearTimeout(timeoutId);
                if (pollId !== null) root.clearTimeout(pollId);
                observer?.disconnect();
                resolve(result);
            };
            const schedulePoll = () => {
                pollId = root.setTimeout(() => {
                    scheduleCheck();
                    schedulePoll();
                }, POLL_INTERVAL_MS);
            };

            function scheduleCheck() {
                if (finished || checkScheduled) return;

                checkScheduled = true;
                nextFrame(root).then(() => {
                    checkScheduled = false;
                    if (finished) return;

                    stableFrames = isSettled() ? stableFrames + 1 : 0;

                    if (stableFrames >= REQUIRED_STABLE_FRAMES) {
                        finish(true);
                    } else if (stableFrames > 0) {
                        scheduleCheck();
                    }
                });
            }

            observer?.observe(root.document.body || root.document.documentElement, {
                childList: true,
                subtree: true,
            });

            if (fonts) {
                void (async () => {
                    await fontReadiness;
                    fontsReady = true;
                    scheduleCheck();
                })();
            }

            const remainingTimeout = Math.max(0, timeoutMs - (Date.now() - startedAt));
            const timeoutId = root.setTimeout(() => finish(false), remainingTimeout);

            scheduleCheck();
            schedulePoll();
        });
    }

    function sendRendererReady(root) {
        if (root.__rfaRendererReadySent) return false;

        root.__rfaRendererReadySent = true;

        try {
            root.nativeRendererReady?.();
        } catch (_) {
            // Browser development has no NativePHP preload bridge.
        }

        return true;
    }

    function signalRendererReady(root) {
        if (hasPendingRenderShells(root) || hasVisibleRenderBlockers(root)) return false;

        return sendRendererReady(root);
    }

    async function signalWhenSettled(root, timeoutMs = DEFAULT_TIMEOUT_MS) {
        const settled = await settleRenderer(root, timeoutMs);

        if (!settled) return false;

        // The settled check resolves inside an animation frame callback, before
        // that frame is painted. Two more frames guarantee the settled DOM has
        // reached the screen, so the main process can show the window at once.
        await nextFrame(root);
        await nextFrame(root);

        return sendRendererReady(root);
    }

    function install(root, { timeoutMs = DEFAULT_TIMEOUT_MS } = {}) {
        if (root.__rfaRendererReadyAttached) return false;

        delete root.__rfaRendererReadySent;
        root.__rfaRendererReadyAttached = true;

        const start = () => signalWhenSettled(root, timeoutMs);

        root.document.addEventListener('livewire:initialized', start, { once: true });

        return true;
    }

    return {
        isInViewport,
        loadRequiredFonts,
        hasPendingRenderShells,
        hasVisibleRenderBlockers,
        settleRenderer,
        signalRendererReady,
        signalWhenSettled,
        install,
    };
});

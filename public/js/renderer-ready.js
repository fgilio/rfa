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

    function hasVisibleFilePlaceholders(root) {
        return Array.from(root.document.querySelectorAll('[data-rfa-diff-file-placeholder]'))
            .some((placeholder) => {
                const fileShell = placeholder.closest('[data-rfa-file-shell]');

                return isInViewport(fileShell || placeholder, root);
            });
    }

    function hasPendingFileShells(root) {
        const review = root.document.querySelector('[data-rfa-expected-file-shells]');

        if (!review) return false;

        const expected = Number(review.dataset.rfaExpectedFileShells || 0);

        if (expected === 0) return false;

        const shells = Array.from(review.querySelectorAll('[data-rfa-file-shell]'));

        return shells.length < expected || shells.some((shell) => {
            const bounds = shell.getBoundingClientRect();

            return bounds.width === 0 || bounds.height === 0;
        });
    }

    async function settleRenderer(root, timeoutMs = DEFAULT_TIMEOUT_MS) {
        const fonts = loadRequiredFonts(root);
        let fontsReady = !fonts;
        let timedOut = false;

        Promise.resolve(fonts).then(
            () => { fontsReady = true; },
            () => { fontsReady = true; },
        );

        const timeoutId = root.setTimeout(() => {
            timedOut = true;
        }, timeoutMs);

        // Livewire initializes while its script is still executing. Window load
        // is the first point where Chromium can expose final shell geometry.
        await waitForWindowLoad(root);

        // Let Alpine's initialization work reach layout before reading geometry.
        await nextFrame(root);
        await nextFrame(root);

        let stableFrames = 0;

        while (!timedOut && stableFrames < 3) {
            stableFrames = fontsReady
                && !hasPendingFileShells(root)
                && !hasVisibleFilePlaceholders(root)
                ? stableFrames + 1
                : 0;

            await nextFrame(root);
        }

        root.clearTimeout(timeoutId);

        return !timedOut
            && fontsReady
            && !hasPendingFileShells(root)
            && !hasVisibleFilePlaceholders(root);
    }

    function signalRendererReady(root) {
        if (root.__rfaRendererReadySent
            || hasPendingFileShells(root)
            || hasVisibleFilePlaceholders(root)) return false;

        root.__rfaRendererReadySent = true;
        root.document.documentElement.dataset.rfaRendererReady = 'true';
        root.dispatchEvent(new root.CustomEvent('rfa:renderer-ready'));

        try {
            root.nativeRendererReady?.();
        } catch (_) {
            // Browser development has no NativePHP preload bridge.
        }

        return true;
    }

    async function signalWhenSettled(root, timeoutMs = DEFAULT_TIMEOUT_MS) {
        const settled = await settleRenderer(root, timeoutMs);

        if (!settled) return false;

        return signalRendererReady(root);
    }

    function install(root, { timeoutMs = DEFAULT_TIMEOUT_MS } = {}) {
        if (root.__rfaRendererReadyAttached) return false;

        delete root.document.documentElement.dataset.rfaRendererReady;
        delete root.__rfaRendererReadySent;
        root.__rfaRendererReadyAttached = true;

        const start = () => signalWhenSettled(root, timeoutMs);

        if (root.Livewire) {
            start();
        } else {
            root.document.addEventListener('livewire:initialized', start, { once: true });
        }

        return true;
    }

    return {
        isInViewport,
        loadRequiredFonts,
        hasPendingFileShells,
        hasVisibleFilePlaceholders,
        settleRenderer,
        signalRendererReady,
        signalWhenSettled,
        install,
    };
});

// Client-side hold-to-quit controller for the NativePHP quit menu item.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.quitConfirmation = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const DEFAULT_THRESHOLD_MS = 1500;
    const DEFAULT_AUTO_DISMISS_MS = 4000;

    function createElement(document, tag, attributes = {}, text = null) {
        const element = document.createElement(tag);

        Object.entries(attributes).forEach(([name, value]) => {
            if (value !== null && value !== undefined) {
                element.setAttribute(name, value);
            }
        });

        if (text !== null) {
            element.textContent = text;
        }

        return element;
    }

    function createOverlay(document, cancel) {
        const overlay = createElement(document, 'div', {
            class: 'fixed inset-0 z-[80] bg-gh-bg/60 backdrop-blur-sm flex items-center justify-center',
            role: 'alertdialog',
            'aria-live': 'polite',
            'aria-label': 'Hold Cmd+Q to quit',
            hidden: '',
        });

        const panel = createElement(document, 'div', {
            class: 'bg-gh-surface/95 border border-gh-border rounded-lg shadow-2xl px-8 py-7 flex flex-col items-center gap-3 min-w-[200px]',
        });

        panel.addEventListener('click', (event) => event.stopPropagation());
        overlay.addEventListener('click', cancel);

        panel.appendChild(createElement(document, 'div', {
            class: 'font-mono text-lg font-semibold text-gh-text',
            'aria-hidden': 'true',
        }, 'Cmd+Q'));

        panel.appendChild(createElement(document, 'div', {
            class: 'font-display text-base font-semibold tracking-brutal text-gh-text whitespace-nowrap',
        }, 'Hold Cmd+Q to Quit'));

        overlay.appendChild(panel);

        return overlay;
    }

    function createQuitConfirmation({
        window,
        document,
        livewire = null,
        thresholdMs = DEFAULT_THRESHOLD_MS,
        autoDismissMs = DEFAULT_AUTO_DISMISS_MS,
    }) {
        let visible = false;
        let armed = false;
        let holdTimer = null;
        let dismissTimer = null;
        let overlay = null;

        function clearTimers() {
            if (holdTimer) {
                window.clearTimeout(holdTimer);
                holdTimer = null;
            }

            if (dismissTimer) {
                window.clearTimeout(dismissTimer);
                dismissTimer = null;
            }
        }

        function ensureOverlay() {
            if (overlay) {
                if (!overlay.isConnected) {
                    document.body.appendChild(overlay);
                }

                return overlay;
            }

            overlay = createOverlay(document, cancel);
            document.body.appendChild(overlay);

            return overlay;
        }

        function hide() {
            if (overlay) {
                overlay.hidden = true;
            }

            visible = false;
            armed = false;
        }

        function cancel() {
            clearTimers();
            hide();
        }

        function commit() {
            clearTimers();
            hide();

            (livewire ?? window.Livewire)?.dispatch?.('quit-now');
        }

        function show() {
            ensureOverlay().hidden = false;
            visible = true;
            armed = false;
            clearTimers();

            holdTimer = window.setTimeout(() => {
                armed = true;
            }, thresholdMs);

            dismissTimer = window.setTimeout(cancel, autoDismissMs);
        }

        function onKeyup(event) {
            if (! visible) {
                return;
            }

            const key = event.key.toLowerCase();

            if (event.key !== 'Meta' && event.key !== 'Control' && key !== 'q') {
                return;
            }

            if (armed) {
                commit();
                return;
            }

            cancel();
        }

        function onKeydown(event) {
            if (event.key !== 'Escape' || ! visible) {
                return;
            }

            event.preventDefault();
            cancel();
        }

        function attach() {
            window.addEventListener('quit-prompt-show', show);
            window.addEventListener('keyup', onKeyup);
            window.addEventListener('keydown', onKeydown);
        }

        function detach() {
            window.removeEventListener('quit-prompt-show', show);
            window.removeEventListener('keyup', onKeyup);
            window.removeEventListener('keydown', onKeydown);
            cancel();

            if (overlay) {
                overlay.remove();
                overlay = null;
            }
        }

        return {
            attach,
            detach,
            show,
            cancel,
            commit,
            isVisible: () => visible,
            isArmed: () => armed,
        };
    }

    function install(root) {
        if (!root?.document || !root.Livewire || root.__quitConfirmationAttached) {
            return false;
        }

        root.__quitConfirmationAttached = true;
        root.__quitConfirmation = createQuitConfirmation({
            window: root,
            document: root.document,
        });
        root.__quitConfirmation.attach();

        return true;
    }

    function autoInstall(root) {
        if (!root?.document) {
            return false;
        }

        if (root.Livewire) {
            return install(root);
        }

        if (root.__quitConfirmationInstallQueued) {
            return false;
        }

        root.__quitConfirmationInstallQueued = true;
        root.document.addEventListener('livewire:init', () => {
            delete root.__quitConfirmationInstallQueued;
            install(root);
        }, { once: true });

        return true;
    }

    return { createQuitConfirmation, install, autoInstall };
});

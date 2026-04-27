// Alpine state factory for right-click context menus. Exposed both as an
// Alpine.data('contextMenu') component and as a window-level factory so the
// fields (`ctxOpen`, `ctxX`, `ctxY`, `openCtx`, `closeCtx`) can be spread into
// another component's x-data.
//
// A module-level `currentlyOpen` ensures only one menu is open at a time.
// Without it, each row in a list (e.g. the project picker) keeps its own
// `ctxOpen`, so right-clicking row B leaves row A's menu floating.
//
// Field names are deliberately scoped (`ctx*`) so they don't collide with
// panels / drawers that also use names like `open`, `x`, or `y`.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.contextMenuState = api.contextMenuState;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    let currentlyOpen = null;

    function contextMenuState() {
        return {
            ctxOpen: false,
            ctxX: 0,
            ctxY: 0,

            openCtx(event) {
                if (currentlyOpen && currentlyOpen !== this) {
                    currentlyOpen.closeCtx();
                }
                currentlyOpen = this;
                const margin = 8;
                const menuW = 200;
                const menuH = 80;
                this.ctxX = Math.min(event.clientX, window.innerWidth - menuW - margin);
                this.ctxY = Math.min(event.clientY, window.innerHeight - menuH - margin);
                this.ctxOpen = true;
            },

            closeCtx() {
                this.ctxOpen = false;
                if (currentlyOpen === this) {
                    currentlyOpen = null;
                }
            },
        };
    }

    function autoInstall(root) {
        const init = () => root.Alpine.data('contextMenu', contextMenuState);
        root.Alpine ? init() : root.document.addEventListener('alpine:init', init);
    }

    // Reset hook for tests — never called from production code.
    function __resetForTests() {
        currentlyOpen = null;
    }

    return { contextMenuState, autoInstall, __resetForTests };
});

// Alpine state factory for right-click context menus. Exposed both as an
// Alpine.data('contextMenu') component and as a window-level factory so the
// fields (`ctxOpen`, `ctxX`, `ctxY`, `openCtx`, `closeCtx`) can be spread into
// another component's x-data.
//
// Field names are deliberately scoped (`ctx*`) so they don't collide with
// panels / drawers that also use names like `open`, `x`, or `y`.
(function () {
    window.contextMenuState = function () {
        return {
            ctxOpen: false,
            ctxX: 0,
            ctxY: 0,

            openCtx(event) {
                const margin = 8;
                const menuW = 200;
                const menuH = 80;
                this.ctxX = Math.min(event.clientX, window.innerWidth - menuW - margin);
                this.ctxY = Math.min(event.clientY, window.innerHeight - menuH - margin);
                this.ctxOpen = true;
            },

            closeCtx() {
                this.ctxOpen = false;
            },
        };
    };

    function init() {
        Alpine.data('contextMenu', window.contextMenuState);
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

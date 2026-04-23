// Alpine state factory for right-click context menus. Exposed both as an
// Alpine.data('contextMenu') component and as a window-level factory so the
// fields (`open`, `x`, `y`, `openAt`, `close`) can be spread into another
// component's x-data, e.g. `{ ...contextMenuState(), status: null }`.
(function () {
    window.contextMenuState = function () {
        return {
            open: false,
            x: 0,
            y: 0,

            openAt(event) {
                const margin = 8;
                const menuW = 200;
                const menuH = 80;
                this.x = Math.min(event.clientX, window.innerWidth - menuW - margin);
                this.y = Math.min(event.clientY, window.innerHeight - menuH - margin);
                this.open = true;
            },

            close() {
                this.open = false;
            },
        };
    };

    function init() {
        Alpine.data('contextMenu', window.contextMenuState);
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

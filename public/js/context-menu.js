// Right-click context menu state for Alpine.
//
// Two usage modes:
//   1. Standalone:   <div x-data="contextMenu()" @contextmenu.prevent="openAt($event)">
//   2. Composed:     <div x-data="{ ...contextMenuState(), foo: 1 }" @contextmenu.prevent="openAt($event)">
//
// Paired with <x-remote-link-menu> which reads `open`, `x`, `y`, `close()`.
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

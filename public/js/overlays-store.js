(function () {
    // Tracks the one overlay that's currently open (project-picker, branch-explorer,
    // comments-drawer, etc.). Only one overlay can be active at a time; opening one
    // causes the others' `x-effect` watchers to auto-close.
    function init() {
        Alpine.store('overlays', {
            current: null,
            open(name) { this.current = name; },
            close() { this.current = null; },
            is(name) { return this.current === name; },
        });
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

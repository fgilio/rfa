(function () {
    // Migrate ad-hoc localStorage keys (one-time)
    let old = localStorage.getItem('rfa-sidebar-width');
    if (old !== null) {
        const parsed = parseInt(old);
        if (!isNaN(parsed) && parsed > 0) {
            localStorage.setItem('rfa.sidebarWidth', JSON.stringify(parsed));
        }
        localStorage.removeItem('rfa-sidebar-width');
    }

    // Pre-paint guard for the persisted collapsed sidebar. Alpine's x-show is
    // what hides it, but Alpine boots with Livewire at the end of <body> —
    // long after the 288px aside has painted. This script runs in <head>, so
    // it can mark the document before first paint and hand authority back to
    // x-show the moment Alpine has walked the tree. Paired with the
    // `.rfa-boot-sidebar-collapsed [data-sidebar-collapsible]` rule in the
    // layout, which deliberately carries no !important so it loses to x-show.
    const clearBootState = () => {
        document.documentElement.classList.remove('rfa-boot-sidebar-collapsed');
    };

    if (window.Alpine) {
        // Re-entry after a wire:navigate re-ran the head scripts: Alpine is
        // already live, so x-show rules and a boot class would never be cleared.
        clearBootState();
    } else {
        if (localStorage.getItem('rfa.sidebarCollapsed') === 'true') {
            document.documentElement.classList.add('rfa-boot-sidebar-collapsed');
        }
        document.addEventListener('alpine:initialized', clearBootState, { once: true });
    }

    function init() {
        Alpine.store('settings', {
            collapseAll: Alpine.$persist(false).as('rfa.collapseAll'),
            sidebarWidth: Alpine.$persist(288).as('rfa.sidebarWidth'),
            sidebarCollapsed: Alpine.$persist(false).as('rfa.sidebarCollapsed'),
            diffViewMode: Alpine.$persist('unified').as('rfa.diffViewMode'),
            // The one mutation point for sidebar visibility: the shortcut
            // (registered by resizable-sidebar-shell), the header button, and
            // the native View-menu item all land here, so the three can't
            // drift into separate notions of "collapsed".
            toggleSidebar() {
                this.sidebarCollapsed = !this.sidebarCollapsed;
            },
        });
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

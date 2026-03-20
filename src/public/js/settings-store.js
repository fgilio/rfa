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

    old = localStorage.getItem('rfa-sort');
    if (old !== null) {
        localStorage.setItem('rfa.dashboardSort', JSON.stringify(old));
        localStorage.removeItem('rfa-sort');
    }

    function init() {
        Alpine.store('settings', {
            collapseAll: Alpine.$persist(false).as('rfa.collapseAll'),
            sidebarWidth: Alpine.$persist(288).as('rfa.sidebarWidth'),
            dashboardSort: Alpine.$persist('recent').as('rfa.dashboardSort'),
        });
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

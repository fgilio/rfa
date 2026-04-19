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

    function init() {
        Alpine.store('settings', {
            collapseAll: Alpine.$persist(false).as('rfa.collapseAll'),
            sidebarWidth: Alpine.$persist(288).as('rfa.sidebarWidth'),
        });
    }

    window.Alpine ? init() : document.addEventListener('alpine:init', init);
})();

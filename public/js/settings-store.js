(function (root, factory) {
    const api = factory();

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.rfaSettingsStore = api;
        api.install(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const DEFAULT_SIDEBAR_WIDTH = 288;
    const MIN_SIDEBAR_WIDTH = 200;
    const MAX_SIDEBAR_WIDTH = 600;

    function parseSidebarWidth(value) {
        if (value === null) return null;

        try {
            const width = JSON.parse(value);

            if (typeof width !== 'number' || !Number.isFinite(width) || width <= 0) return null;

            return Math.min(MAX_SIDEBAR_WIDTH, Math.max(MIN_SIDEBAR_WIDTH, width));
        } catch (_) {
            return null;
        }
    }

    function restoreSidebarWidth(root) {
        const storageKey = 'rfa.sidebarWidth';
        const storedWidth = root.localStorage.getItem(storageKey);
        const width = parseSidebarWidth(storedWidth);

        if (width === null) {
            if (storedWidth !== null) root.localStorage.removeItem(storageKey);

            root.document.documentElement.style.setProperty('--sidebar-w', `${DEFAULT_SIDEBAR_WIDTH}px`);

            return DEFAULT_SIDEBAR_WIDTH;
        }

        root.localStorage.setItem(storageKey, JSON.stringify(width));
        root.document.documentElement.style.setProperty('--sidebar-w', `${width}px`);

        return width;
    }

    function install(root) {
        // Migrate ad-hoc localStorage keys (one-time)
        const old = root.localStorage.getItem('rfa-sidebar-width');
        if (old !== null) {
            const parsed = parseInt(old);
            if (!isNaN(parsed) && parsed > 0) {
                root.localStorage.setItem('rfa.sidebarWidth', JSON.stringify(parsed));
            }
            root.localStorage.removeItem('rfa-sidebar-width');
        }

        restoreSidebarWidth(root);

        // Pre-paint guard for the persisted collapsed sidebar. Alpine's x-show is
        // what hides it, but Alpine boots with Livewire at the end of <body> —
        // long after the 288px aside has painted. This script runs in <head>, so
        // it can mark the document before first paint and hand authority back to
        // x-show the moment Alpine has walked the tree. Paired with the
        // `.rfa-boot-sidebar-collapsed [data-sidebar-collapsible]` rule in the
        // layout, which deliberately carries no !important so it loses to x-show.
        const clearBootState = () => {
            root.document.documentElement.classList.remove('rfa-boot-sidebar-collapsed');
        };

        if (root.Alpine) {
            // Re-entry after a wire:navigate re-ran the head scripts: Alpine is
            // already live, so x-show rules and a boot class would never be cleared.
            clearBootState();
        } else {
            if (root.localStorage.getItem('rfa.sidebarCollapsed') === 'true') {
                root.document.documentElement.classList.add('rfa-boot-sidebar-collapsed');
            }
            root.document.addEventListener('alpine:initialized', clearBootState, { once: true });
        }

        function init() {
            root.Alpine.store('settings', {
                collapseAll: root.Alpine.$persist(false).as('rfa.collapseAll'),
                sidebarWidth: root.Alpine.$persist(DEFAULT_SIDEBAR_WIDTH).as('rfa.sidebarWidth'),
                sidebarCollapsed: root.Alpine.$persist(false).as('rfa.sidebarCollapsed'),
                diffViewMode: root.Alpine.$persist('unified').as('rfa.diffViewMode'),
                // The one mutation point for sidebar visibility: the shortcut
                // (registered by resizable-sidebar-shell), the header button, and
                // the native View-menu item all land here, so the three can't
                // drift into separate notions of "collapsed".
                toggleSidebar() {
                    this.sidebarCollapsed = !this.sidebarCollapsed;
                },
            });
        }

        root.Alpine
            ? init()
            : root.document.addEventListener('alpine:init', init);
    }

    return {
        parseSidebarWidth,
        restoreSidebarWidth,
        install,
    };
});

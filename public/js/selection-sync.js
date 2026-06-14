// Keeps a review component's active-file selection on a visible row after each
// of its server round-trips.
//
// A filter or hide-reviewed re-render can drop the active file from the visible
// list, and Livewire's morph leaves the selection pointing at the now-hidden
// row, so the sidebar highlight and j/k cursor lose their anchor. This registers
// a scoped `commit` hook that runs the caller's resync after the component's own
// commits succeed and the DOM has morphed.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.rfaSelectionSync = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    function createSelectionSync({ livewire, getWireId, onResync = () => {} }) {
        let cleanupCommitHook = null;

        return {
            attach() {
                if (cleanupCommitHook !== null || !livewire?.hook) {
                    return false;
                }

                cleanupCommitHook = livewire.hook('commit', ({ component, succeed }) => {
                    if (component.id !== getWireId()) {
                        return;
                    }

                    succeed(() => onResync());
                });

                return true;
            },

            detach() {
                if (cleanupCommitHook !== null) {
                    cleanupCommitHook();
                    cleanupCommitHook = null;
                }
            },
        };
    }

    return { createSelectionSync };
});

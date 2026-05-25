(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.rfaPendingSaves = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    function createPendingSavesGuard({ root, livewire, getWireId, onPendingSavesChanged = () => {} }) {
        let pendingSaves = 0;
        let cleanupCommitHook = null;
        let beforeUnloadHandler = null;

        function setPendingSaves(count) {
            pendingSaves = Math.max(0, count);
            onPendingSavesChanged(pendingSaves);
        }

        return {
            get pendingSaves() {
                return pendingSaves;
            },

            attach() {
                if (cleanupCommitHook !== null || beforeUnloadHandler !== null) {
                    return false;
                }

                const wireId = getWireId();

                cleanupCommitHook = livewire.hook('commit', ({ component, succeed, fail }) => {
                    if (component.id !== wireId) {
                        return;
                    }

                    setPendingSaves(pendingSaves + 1);

                    const done = () => setPendingSaves(pendingSaves - 1);
                    succeed(done);
                    fail(done);
                });

                beforeUnloadHandler = (event) => {
                    if (pendingSaves <= 0) {
                        return;
                    }

                    event.preventDefault();
                    event.returnValue = '';
                };

                root.addEventListener('beforeunload', beforeUnloadHandler);

                return true;
            },

            detach() {
                if (cleanupCommitHook !== null) {
                    cleanupCommitHook();
                    cleanupCommitHook = null;
                }

                if (beforeUnloadHandler !== null) {
                    root.removeEventListener('beforeunload', beforeUnloadHandler);
                    beforeUnloadHandler = null;
                }

                setPendingSaves(0);
            },
        };
    }

    return { createPendingSavesGuard };
});

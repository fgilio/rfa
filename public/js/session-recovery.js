// JS timers freeze during macOS sleep, so the <livewire:keepalive> poll
// can't bump the session before wake. If a 419 slips through, replace
// Livewire's built-in "page expired" confirm() with a silent reload.
//
// The recent-reload guard prevents an infinite loop if a 419 keeps
// recurring (e.g. broken session storage): after one silent reload
// within 10s, fall through to Livewire's default handler so the user
// sees a surface they can act on instead of a reload spinner.
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.sessionRecovery = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const STORAGE_KEY = '__rfa419RecoveryAt';
    const RECOVERY_TTL_MS = 10_000;

    function shouldRecover({ status, now, lastRecoveryAt }) {
        if (status !== 419) {
            return false;
        }
        if (now - (lastRecoveryAt || 0) < RECOVERY_TTL_MS) {
            return false;
        }
        return true;
    }

    function install(root) {
        if (typeof root.Livewire === 'undefined' || root.__sessionRecoveryAttached) {
            return false;
        }
        root.__sessionRecoveryAttached = true;

        root.Livewire.interceptRequest(({ onError }) => {
            onError(({ response, preventDefault }) => {
                const lastRecoveryAt = Number(
                    root.sessionStorage.getItem(STORAGE_KEY) || 0
                );

                if (!shouldRecover({
                    status: response.status,
                    now: Date.now(),
                    lastRecoveryAt,
                })) {
                    return;
                }

                root.sessionStorage.setItem(STORAGE_KEY, String(Date.now()));
                preventDefault();
                root.location.reload();
            });
        });

        return true;
    }

    function autoInstall(root) {
        if (root.Livewire) {
            install(root);
        } else {
            root.document.addEventListener('livewire:init', () => install(root));
        }
    }

    return { shouldRecover, install, autoInstall };
});

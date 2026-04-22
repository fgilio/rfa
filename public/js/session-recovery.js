// JS timers freeze during macOS sleep, so the <livewire:keepalive> poll
// can't bump the session before wake. If a 419 slips through, replace
// Livewire's built-in "page expired" confirm() with a silent reload.
//
// The recent-reload guard prevents an infinite loop if a 419 keeps
// recurring (e.g. broken session storage): after one silent reload
// within 10s, fall through to Livewire's default handler so the user
// sees a surface they can act on instead of a reload spinner.
document.addEventListener('livewire:init', () => {
    Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status !== 419) {
                return;
            }

            const key = '__rfa419RecoveryAt';
            const lastRecoveryAt = Number(sessionStorage.getItem(key) || 0);
            if (Date.now() - lastRecoveryAt < 10_000) {
                return;
            }

            sessionStorage.setItem(key, String(Date.now()));
            preventDefault();
            window.location.reload();
        });
    });
});

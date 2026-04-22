// JS timers freeze during macOS sleep, so the <livewire:keepalive> poll
// can't bump the session before wake. If a 419 slips through, replace
// Livewire's built-in "page expired" confirm() with a silent reload.
document.addEventListener('livewire:init', () => {
    Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status === 419) {
                preventDefault();
                window.location.reload();
            }
        });
    });
});

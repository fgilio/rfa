// Detects PHP backend death and surfaces a recovery overlay.
//
// Two failure signals: Livewire request errors (interceptRequest hook) and an
// active /_rfa/health probe. The store stays at `connected` during normal
// operation so visitAndLoad's networkidle wait resolves cleanly under tests.

(function () {
    const STATE_CONNECTED = 'connected';
    const STATE_RECONNECTING = 'reconnecting';
    const STATE_UNRECOVERABLE = 'unrecoverable';

    const POLL_INTERVAL_MS = 1000;
    const POLL_TIMEOUT_MS = 1500;
    const UNRECOVERABLE_AFTER_MS = 30_000;
    const UNRECOVERABLE_AFTER_TICKS = UNRECOVERABLE_AFTER_MS / POLL_INTERVAL_MS;
    const HEALTH_URL = '/_rfa/health';

    function init() {
        Alpine.store('backendHealth', {
            state: STATE_CONNECTED,
            attempts: 0,
            lastError: null,

            _pollAbort: null,
            _pollTimer: null,

            isVisible() {
                return this.state !== STATE_CONNECTED;
            },

            // First strike starts a probe to confirm; second strike from any
            // source flips the overlay. Rides out single transient blips
            // (e.g. one Livewire 500 from a one-off app bug).
            reportFailure(reason) {
                if (reason) {
                    this.lastError = reason;
                }
                if (this.state !== STATE_CONNECTED) {
                    return;
                }
                if (this._pollTimer) {
                    this._flipReconnecting();
                } else {
                    this._startPolling();
                }
            },

            reportSuccess() {
                if (this.state === STATE_CONNECTED) {
                    this._stopPolling();
                    return;
                }
                this._onRecovery();
            },

            // Terminal state — auto-recovering would risk a reload loop against
            // a flapping backend. From here the user picks Force Quit or Restart.
            flipUnrecoverable(reason) {
                this.state = STATE_UNRECOVERABLE;
                if (reason) {
                    this.lastError = reason;
                }
                this._stopPolling();
            },

            _flipReconnecting() {
                this.state = STATE_RECONNECTING;
                this.attempts = 0;
                this._startPolling();
            },

            _startPolling() {
                if (this._pollTimer) {
                    return;
                }
                const tick = () => {
                    if (this.state === STATE_RECONNECTING) {
                        this.attempts += 1;
                        if (this.attempts >= UNRECOVERABLE_AFTER_TICKS) {
                            this.flipUnrecoverable(this.lastError);
                            return;
                        }
                    }
                    this._probe();
                };
                tick();
                this._pollTimer = setInterval(tick, POLL_INTERVAL_MS);
            },

            _stopPolling() {
                if (this._pollTimer) {
                    clearInterval(this._pollTimer);
                    this._pollTimer = null;
                }
                if (this._pollAbort) {
                    this._pollAbort.abort();
                    this._pollAbort = null;
                }
            },

            _probe() {
                // Cancel any in-flight probe before issuing the next one;
                // a slow response otherwise stacks pollers.
                if (this._pollAbort) {
                    this._pollAbort.abort();
                }
                const controller = new AbortController();
                this._pollAbort = controller;

                const timeoutId = setTimeout(() => controller.abort(), POLL_TIMEOUT_MS);

                fetch(HEALTH_URL, {
                    method: 'GET',
                    signal: controller.signal,
                    cache: 'no-store',
                    credentials: 'omit',
                })
                    .then((response) => {
                        clearTimeout(timeoutId);
                        if (response.ok) {
                            this.reportSuccess();
                        } else {
                            this.reportFailure(`Probe HTTP ${response.status}`);
                        }
                    })
                    .catch((error) => {
                        clearTimeout(timeoutId);
                        const reason = error?.name === 'AbortError'
                            ? 'Probe timed out'
                            : `Probe network error: ${error?.message ?? 'unknown'}`;
                        this.reportFailure(reason);
                    });
            },

            _onRecovery() {
                this._stopPolling();
                this.state = STATE_CONNECTED;
                window.location.reload();
            },
        });
    }

    function isLivewireBackendFailure(response) {
        // 4xx (incl. 419) is user/input error, not backend death.
        if (!response) {
            return true;
        }
        return response.status >= 500;
    }

    document.addEventListener('livewire:init', () => {
        if (typeof Livewire === 'undefined') {
            return;
        }
        Livewire.interceptRequest(({ onError }) => {
            onError(({ response }) => {
                if (!isLivewireBackendFailure(response)) {
                    return;
                }
                const reason = response
                    ? `Livewire request failed (HTTP ${response.status})`
                    : 'Livewire request failed (network error)';
                Alpine.store('backendHealth')?.reportFailure(reason);
            });
        });
    });

    if (window.Alpine) {
        init();
    } else {
        document.addEventListener('alpine:init', init);
    }
})();

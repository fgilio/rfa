(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.rfaRuntimeDiagnostics = api;
        api.autoInstall(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const DEFAULT_SAMPLE_INTERVAL_MS = 60_000;
    const DEFAULT_PROCESS_SAMPLE_INTERVAL_MS = 300_000;
    const COMMIT_SAMPLE_THROTTLE_MS = 30_000;

    function bytesToMegabytes(bytes) {
        return Math.round((bytes / 1024 / 1024) * 1000) / 1000;
    }

    function collectHeap(win) {
        const memory = win.performance && win.performance.memory;

        if (!memory) {
            return null;
        }

        return {
            usedJSHeapSize: memory.usedJSHeapSize,
            totalJSHeapSize: memory.totalJSHeapSize,
            jsHeapSizeLimit: memory.jsHeapSizeLimit,
            usedJSHeapSizeMb: bytesToMegabytes(memory.usedJSHeapSize),
            totalJSHeapSizeMb: bytesToMegabytes(memory.totalJSHeapSize),
        };
    }

    function collectNavigation(win) {
        const [entry] = win.performance?.getEntriesByType?.('navigation') || [];

        return {
            type: entry?.type || null,
            domCompleteMs: entry ? Math.round(entry.domComplete) : null,
            resources: win.performance?.getEntriesByType?.('resource')?.length || 0,
        };
    }

    function collectDom(doc) {
        const diffFiles = Array.from(doc.querySelectorAll('[data-rfa-diff-file]'));

        return {
            nodes: doc.getElementsByTagName('*').length,
            livewireComponents: doc.querySelectorAll('[wire\\:id]').length,
            diffFiles: diffFiles.length,
            expandedDiffFiles: diffFiles.filter((el) => el.dataset.collapsed === 'false').length,
            diffLines: doc.querySelectorAll('.diff-line').length,
            comments: doc.querySelectorAll('[id^="comment-"]').length,
        };
    }

    function collectSample(root, reason, includeProcessSnapshot) {
        return {
            reason,
            includeProcessSnapshot,
            url: root.location.href,
            hidden: root.document.hidden,
            focused: root.document.hasFocus(),
            viewport: {
                width: root.innerWidth,
                height: root.innerHeight,
                devicePixelRatio: root.devicePixelRatio || 1,
            },
            heap: collectHeap(root),
            dom: collectDom(root.document),
            navigation: collectNavigation(root),
        };
    }

    function postSample(root, payload) {
        const config = root.rfaDiagnosticsConfig || {};
        const token = root.document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        return root.fetch(config.endpoint || '/api/diagnostics/browser', {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        }).catch(() => {});
    }

    function install(root) {
        const config = root.rfaDiagnosticsConfig || {};

        if (root.__rfaRuntimeDiagnosticsAttached || config.enabled === false) {
            return false;
        }

        root.__rfaRuntimeDiagnosticsAttached = true;

        const sampleIntervalMs = Number(config.sampleIntervalMs || DEFAULT_SAMPLE_INTERVAL_MS);
        const processSampleIntervalMs = Number(config.processSampleIntervalMs || DEFAULT_PROCESS_SAMPLE_INTERVAL_MS);
        let lastProcessSampleAt = 0;
        let lastCommitSampleAt = 0;

        function shouldIncludeProcessSnapshot(now) {
            if (now - lastProcessSampleAt < processSampleIntervalMs) {
                return false;
            }

            lastProcessSampleAt = now;
            return true;
        }

        function sample(reason, forceProcessSnapshot = false) {
            const now = Date.now();
            let includeProcessSnapshot = shouldIncludeProcessSnapshot(now);

            if (forceProcessSnapshot) {
                lastProcessSampleAt = now;
                includeProcessSnapshot = true;
            }

            return postSample(root, collectSample(root, reason, includeProcessSnapshot));
        }

        sample('boot', true);

        const heartbeatId = root.setInterval(() => sample('heartbeat'), Math.max(sampleIntervalMs, 10_000));

        root.addEventListener('beforeunload', () => {
            root.clearInterval(heartbeatId);
            sample('beforeunload', true);
        });

        root.document.addEventListener('visibilitychange', () => sample('visibility'));
        root.document.addEventListener('livewire:navigated', () => sample('navigate', true));

        root.document.addEventListener('livewire:init', () => {
            if (!root.Livewire || root.__rfaRuntimeDiagnosticsLivewireHooked) {
                return;
            }

            root.__rfaRuntimeDiagnosticsLivewireHooked = true;
            root.Livewire.hook('commit', ({ succeed, fail }) => {
                const mark = () => {
                    const now = Date.now();

                    if (now - lastCommitSampleAt < COMMIT_SAMPLE_THROTTLE_MS) {
                        return;
                    }

                    lastCommitSampleAt = now;
                    sample('livewire.commit');
                };

                succeed(mark);
                fail(mark);
            });
        });

        return true;
    }

    function autoInstall(root) {
        if (!root) {
            return false;
        }

        if (root.document.readyState === 'loading') {
            root.document.addEventListener('DOMContentLoaded', () => install(root), { once: true });
            return true;
        }

        return install(root);
    }

    return { bytesToMegabytes, collectSample, install, autoInstall };
});

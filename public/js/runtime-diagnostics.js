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

    function nowMs(root) {
        return root.performance?.now ? root.performance.now() : Date.now();
    }

    function roundMs(value) {
        return value === null || value === undefined ? null : Math.round(value);
    }

    function bytesToMegabytes(bytes) {
        return Math.round((bytes / 1024 / 1024) * 1000) / 1000;
    }

    function installLongTaskObserver(root) {
        if (root.__rfaLongTasks) {
            return root.__rfaLongTasks;
        }

        const metrics = {
            count: 0,
            totalMs: 0,
            maxMs: 0,
            durations: [],
        };

        root.__rfaLongTasks = metrics;

        if (!root.PerformanceObserver) {
            return metrics;
        }

        try {
            const observer = new root.PerformanceObserver((list) => {
                for (const entry of list.getEntries()) {
                    const duration = Math.max(0, entry.duration || 0);

                    metrics.count++;
                    metrics.totalMs += duration;
                    metrics.maxMs = Math.max(metrics.maxMs, duration);
                    metrics.durations.push(duration);
                }
            });

            observer.observe({ type: 'longtask', buffered: true });
            metrics.observer = observer;
        } catch {
            // Long Task API support varies by runtime.
        }

        return metrics;
    }

    function collectLongTasks(root) {
        const metrics = root.__rfaLongTasks;

        if (!metrics) {
            return null;
        }

        return {
            count: metrics.count,
            totalMs: roundMs(metrics.totalMs),
            maxMs: roundMs(metrics.maxMs),
        };
    }

    function longTaskDelta(root, start) {
        const current = collectLongTasks(root);

        if (!current || !start) {
            return current;
        }

        return {
            count: Math.max(0, current.count - start.count),
            totalMs: Math.max(0, current.totalMs - start.totalMs),
            maxMs: roundMs(Math.max(0, ...((root.__rfaLongTasks?.durations || []).slice(start.count, current.count)))),
        };
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

    function collectSample(root, reason, includeProcessSnapshot, timings = {}) {
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
            timings: {
                longTasks: collectLongTasks(root),
                ...timings,
            },
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
        installLongTaskObserver(root);

        const sampleIntervalMs = Number(config.sampleIntervalMs || DEFAULT_SAMPLE_INTERVAL_MS);
        const processSampleIntervalMs = Number(config.processSampleIntervalMs || DEFAULT_PROCESS_SAMPLE_INTERVAL_MS);
        const pendingDiffActions = new Map();
        let lastProcessSampleAt = 0;
        let lastCommitSampleAt = 0;

        function shouldIncludeProcessSnapshot(now) {
            if (now - lastProcessSampleAt < processSampleIntervalMs) {
                return false;
            }

            lastProcessSampleAt = now;
            return true;
        }

        function sample(reason, forceProcessSnapshot = false, timings = {}) {
            const now = Date.now();
            let includeProcessSnapshot = shouldIncludeProcessSnapshot(now);

            if (forceProcessSnapshot) {
                lastProcessSampleAt = now;
                includeProcessSnapshot = true;
            }

            return postSample(root, collectSample(root, reason, includeProcessSnapshot, timings));
        }

        function diffActionKey(detail) {
            return `${detail?.fileId || 'unknown'}:${detail?.action || 'unknown'}`;
        }

        root.addEventListener('rfa:diff-action-start', (event) => {
            pendingDiffActions.set(diffActionKey(event.detail), {
                startedAtMs: nowMs(root),
                longTasks: collectLongTasks(root),
            });
        });

        root.addEventListener('rfa:diff-action-completed', (event) => {
            const detail = event.detail || {};
            const started = pendingDiffActions.get(diffActionKey(detail)) || null;
            pendingDiffActions.delete(diffActionKey(detail));

            const elapsedMs = started ? roundMs(nowMs(root) - started.startedAtMs) : null;
            const diffAction = {
                fileId: detail.fileId || null,
                action: detail.action || null,
                elapsedMs,
                phpMs: roundMs(detail.phpMs ?? detail.durationMs),
                hunkCount: detail.hunkCount ?? detail.hunk_count ?? null,
                diffLines: detail.diffLineCount ?? detail.diff_line_count ?? null,
                lineContentBytes: detail.lineContentBytes ?? detail.line_content_bytes ?? null,
                tooLarge: detail.tooLarge ?? detail.too_large ?? false,
                binary: detail.binary ?? false,
                cached: detail.cached ?? false,
            };

            root.__rfaLastDiffActionTiming = diffAction;
            root.__rfaDiffActionTimings = root.__rfaDiffActionTimings || {};
            if (diffAction.action) {
                root.__rfaDiffActionTimings[diffAction.action] = diffAction;
            }

            sample('diff.action', false, {
                diffAction,
                longTasksDuringAction: longTaskDelta(root, started?.longTasks),
            });
        });

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
                const startedAtMs = nowMs(root);
                const startedLongTasks = collectLongTasks(root);

                const mark = (status) => {
                    const now = Date.now();

                    if (now - lastCommitSampleAt < COMMIT_SAMPLE_THROTTLE_MS) {
                        return;
                    }

                    lastCommitSampleAt = now;
                    sample('livewire.commit', false, {
                        livewireCommit: {
                            status,
                            elapsedMs: roundMs(nowMs(root) - startedAtMs),
                        },
                        longTasksDuringCommit: longTaskDelta(root, startedLongTasks),
                    });
                };

                succeed(() => mark('succeeded'));
                fail(() => mark('failed'));
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

    return { bytesToMegabytes, collectSample, collectLongTasks, install, autoInstall };
});

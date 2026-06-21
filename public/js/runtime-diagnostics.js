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
    const DEFAULT_ANIMATION_DETAIL_LIMIT = 20;
    const DEFAULT_ANIMATION_CLASS_SUMMARY_LIMIT = 20;
    const COMMIT_SAMPLE_THROTTLE_MS = 30_000;
    const RECENT_POLL_WINDOW_MS = 10 * 60_000;

    function nowMs(root) {
        return root.performance?.now ? root.performance.now() : Date.now();
    }

    function roundMs(value) {
        return value === null || value === undefined ? null : Math.round(value);
    }

    function shortString(value, limit = 96) {
        if (value === null || value === undefined) {
            return null;
        }

        const string = String(value);

        return string.length > limit ? string.slice(0, limit - 3) + '...' : string;
    }

    function textString(value, limit = 120) {
        if (value === null || value === undefined) {
            return null;
        }

        const text = String(value).replace(/\s+/g, ' ').trim();

        return text ? shortString(text, limit) : null;
    }

    function plainString(value, limit = 96) {
        return typeof value === 'string' && value.trim() !== '' ? shortString(value, limit) : null;
    }

    function stringList(values, limit = 20) {
        if (!Array.isArray(values)) {
            return [];
        }

        return values
            .map(value => shortString(value, 96))
            .filter(Boolean)
            .slice(0, limit);
    }

    function uniqueStringList(values, limit = 20) {
        return Array.from(new Set(stringList(values, limit * 2))).slice(0, limit);
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

    function collectScreen(win) {
        if (!win.screen) {
            return null;
        }

        return {
            width: win.screen.width || 0,
            height: win.screen.height || 0,
            availWidth: win.screen.availWidth || 0,
            availHeight: win.screen.availHeight || 0,
        };
    }

    function collectVisibility(root) {
        const state = root.__rfaFocusState || {
            at: Date.now(),
            visibleAt: Date.now(),
        };

        return {
            state: root.document.visibilityState || (root.document.hidden ? 'hidden' : 'visible'),
            hidden: root.document.hidden,
            focused: root.document.hasFocus(),
            focusAgeMs: Math.max(0, Date.now() - (state.at || Date.now())),
            visibilityAgeMs: Math.max(0, Date.now() - (state.visibleAt || Date.now())),
        };
    }

    function collectActivity(root) {
        const activity = root.__rfaLastActivity || null;

        if (!activity) {
            return null;
        }

        return {
            idleMs: Math.max(0, Date.now() - activity.at),
            lastEvent: shortString(activity.type, 64),
        };
    }

    function collectScroll(win) {
        const doc = win.document.documentElement;
        const body = win.document.body;
        const scrollHeight = Math.max(doc?.scrollHeight || 0, body?.scrollHeight || 0);
        const viewportHeight = win.innerHeight || doc?.clientHeight || 0;

        return {
            x: Math.max(0, Math.round(win.scrollX || doc?.scrollLeft || body?.scrollLeft || 0)),
            y: Math.max(0, Math.round(win.scrollY || doc?.scrollTop || body?.scrollTop || 0)),
            maxY: Math.max(0, Math.round(scrollHeight - viewportHeight)),
        };
    }

    function collectClassCounters(doc) {
        const counters = {
            animatedElements: 0,
            animateSpin: 0,
            animatePing: 0,
            animatePulse: 0,
            backdropBlur: 0,
            sticky: 0,
        };

        for (const el of doc.querySelectorAll('[class]')) {
            const classes = Array.from(el.classList || []);

            if (classes.some(name => name.includes('animate-'))) counters.animatedElements++;
            if (classes.some(name => name.includes('animate-spin'))) counters.animateSpin++;
            if (classes.some(name => name.includes('animate-ping'))) counters.animatePing++;
            if (classes.some(name => name.includes('animate-pulse'))) counters.animatePulse++;
            if (classes.some(name => name.includes('backdrop-blur'))) counters.backdropBlur++;
            if (classes.includes('sticky')) counters.sticky++;
        }

        return counters;
    }

    function diagnosticClassNames(el, limit = 20) {
        const classes = Array.from(el.classList || []);
        const diagnosticClasses = classes.filter(name => (
            name.includes('animate-')
            || name.includes('backdrop-blur')
            || name === 'sticky'
            || name.includes('motion-safe')
            || name.includes('loading')
            || name.includes('spinner')
            || name.includes('opacity-')
        ));

        return stringList(diagnosticClasses.length > 0 ? diagnosticClasses : classes, limit);
    }

    function elementSignature(el) {
        const tag = shortString(el.tagName?.toLowerCase() || 'unknown', 32);
        const id = el.id ? `#${shortString(el.id, 48)}` : '';
        const testId = el.getAttribute?.('data-testid');
        const role = el.getAttribute?.('role');
        const classes = diagnosticClassNames(el, 4).map(name => `.${name}`).join('');
        const attributes = [
            testId ? `[data-testid="${shortString(testId, 48)}"]` : null,
            role ? `[role="${shortString(role, 48)}"]` : null,
        ].filter(Boolean).join('');

        return shortString(`${tag}${id}${attributes}${classes}`, 180);
    }

    function livewireElementDetails(root, el) {
        const componentEl = el.closest?.('[wire\\:id]');
        const id = componentEl?.getAttribute?.('wire:id') || null;

        if (!id) {
            return { id: null, name: null };
        }

        let name = null;

        try {
            const component = root.Livewire?.find?.(id);
            name = plainString(component?.name, 128) || plainString(component?.snapshot?.memo?.name, 128);
        } catch {
            name = null;
        }

        return {
            id: shortString(id, 64),
            name: shortString(name, 128),
        };
    }

    function nearestAttribute(el, selector, attribute) {
        return shortString(el.closest?.(selector)?.getAttribute?.(attribute) || null, 96);
    }

    function attributeValue(el, attribute, limit = 96) {
        return shortString(el?.getAttribute?.(attribute) || null, limit);
    }

    function nearestInteractiveElement(el) {
        return el.closest?.('button, [role="button"], a[href], [aria-label], [title], [wire\\:click], [data-loading]');
    }

    function nearestLoadingElement(el) {
        return el.closest?.('[data-loading], [aria-busy="true"], [wire\\:loading], [wire\\:target]');
    }

    function elementBounds(el) {
        if (typeof el.getBoundingClientRect !== 'function') {
            return {};
        }

        const rect = el.getBoundingClientRect();

        return {
            rectX: roundMs(rect.x),
            rectY: roundMs(rect.y),
            rectWidth: roundMs(rect.width),
            rectHeight: roundMs(rect.height),
        };
    }

    function elementState(root, el) {
        if (typeof root.getComputedStyle !== 'function') {
            return {};
        }

        try {
            const style = root.getComputedStyle(el);

            return {
                computedDisplay: shortString(style.display, 32),
                computedVisibility: shortString(style.visibility, 32),
                computedOpacity: shortString(style.opacity, 32),
                computedPointerEvents: shortString(style.pointerEvents, 32),
            };
        } catch {
            return {};
        }
    }

    function interactiveElementDetails(el) {
        const interactive = nearestInteractiveElement(el);
        const loading = nearestLoadingElement(el);

        if (!interactive && !loading) {
            return {};
        }

        const control = interactive || loading;
        const loadingState = !!(
            loading?.hasAttribute?.('data-loading')
            || loading?.getAttribute?.('aria-busy') === 'true'
            || loading?.hasAttribute?.('wire:loading')
        );

        return {
            nearestInteractiveSignature: control ? elementSignature(control) : null,
            nearestButtonLabel: textString(
                attributeValue(control, 'aria-label')
                || attributeValue(control, 'title')
                || attributeValue(control, 'data-tooltip')
                || attributeValue(control, 'tooltip'),
                120,
            ),
            nearestButtonText: textString(control?.textContent, 120),
            nearestButtonTitle: attributeValue(control, 'title', 120),
            nearestButtonRole: attributeValue(control, 'role', 64),
            nearestButtonDisabled: !!(
                control?.disabled
                || control?.hasAttribute?.('disabled')
                || control?.getAttribute?.('aria-disabled') === 'true'
            ),
            nearestLoading: loadingState,
            nearestWireClick: attributeValue(control, 'wire:click', 160),
            nearestWireTarget: attributeValue(control, 'wire:target', 160) || attributeValue(loading, 'wire:target', 160),
        };
    }

    function computedAnimationStyle(root, el) {
        if (typeof root.getComputedStyle !== 'function') {
            return {};
        }

        try {
            const style = root.getComputedStyle(el);
            const hasCssAnimation = style.animationName && style.animationName !== 'none';

            return {
                cssAnimationName: hasCssAnimation ? shortString(style.animationName, 96) : null,
                cssAnimationDuration: hasCssAnimation && style.animationDuration !== '0s' ? shortString(style.animationDuration, 64) : null,
                cssAnimationPlayState: hasCssAnimation ? shortString(style.animationPlayState, 64) : null,
            };
        } catch {
            return {};
        }
    }

    function isVisibleElement(el) {
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects?.().length);
    }

    function describeAnimatedElement(root, el) {
        const livewire = livewireElementDetails(root, el);

        return {
            signature: elementSignature(el),
            tag: shortString(el.tagName?.toLowerCase() || null, 32),
            id: shortString(el.id || null, 64),
            testId: shortString(el.getAttribute?.('data-testid') || null, 96),
            role: shortString(el.getAttribute?.('role') || null, 64),
            classes: diagnosticClassNames(el),
            animationNames: [],
            playStates: [],
            animationCount: 0,
            runningCount: 0,
            maxDurationMs: null,
            connected: !!el.isConnected,
            visible: isVisibleElement(el),
            nearestLivewireId: livewire.id,
            nearestLivewireName: livewire.name,
            nearestTestId: nearestAttribute(el, '[data-testid]', 'data-testid'),
            nearestDiffFileState: nearestAttribute(el, '[data-rfa-diff-file]', 'data-collapsed'),
            ...interactiveElementDetails(el),
            ...elementBounds(el),
            ...elementState(root, el),
            ...computedAnimationStyle(root, el),
        };
    }

    function collectAnimationClassSummary(doc, limit) {
        const counts = new Map();

        for (const el of doc.querySelectorAll('[class]')) {
            for (const className of Array.from(el.classList || [])) {
                if (!className.includes('animate-') && !className.includes('backdrop-blur') && className !== 'sticky') {
                    continue;
                }

                counts.set(className, (counts.get(className) || 0) + 1);
            }
        }

        return Array.from(counts.entries())
            .map(([name, count]) => ({ name: shortString(name, 96), count }))
            .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name))
            .slice(0, limit);
    }

    function animationName(animation) {
        return shortString(
            animation.animationName
            || animation.transitionProperty
            || animation.id
            || animation.constructor?.name
            || null,
            96,
        );
    }

    function animationDurationMs(animation) {
        const timing = animation.effect?.getTiming?.();
        const duration = timing?.duration;

        return typeof duration === 'number' && Number.isFinite(duration) ? roundMs(duration) : null;
    }

    function animationKind(animation) {
        const constructorName = animation.constructor?.name || '';

        if (constructorName.includes('Transition') || animation.transitionProperty) {
            return 'transition';
        }

        if (constructorName.includes('Animation') || animation.animationName) {
            return 'animation';
        }

        return 'unknown';
    }

    function collectAnimationRows(root, animations, detailLimit) {
        const rows = new Map();

        for (const animation of animations) {
            const target = animation.effect?.target;

            if (!target || target.nodeType !== 1) {
                continue;
            }

            const row = rows.get(target) || describeAnimatedElement(root, target);
            const name = animationName(animation);
            const durationMs = animationDurationMs(animation);
            const playState = shortString(animation.playState || null, 32);

            row.animationCount++;

            if (animation.playState === 'running') {
                row.runningCount++;
            }

            if (name && !row.animationNames.includes(name)) {
                row.animationNames.push(name);
            }

            if (playState && !row.playStates.includes(playState)) {
                row.playStates.push(playState);
            }

            if (durationMs !== null) {
                row.maxDurationMs = Math.max(row.maxDurationMs || 0, durationMs);
            }

            rows.set(target, row);
        }

        return Array.from(rows.values())
            .sort((a, b) => (
                b.runningCount - a.runningCount
                || b.animationCount - a.animationCount
                || a.signature.localeCompare(b.signature)
            ))
            .slice(0, detailLimit)
            .map(row => ({
                ...row,
                animationNames: uniqueStringList(row.animationNames, 12),
                playStates: uniqueStringList(row.playStates, 8),
                classes: uniqueStringList(row.classes, 20),
            }));
    }

    function fallbackAnimationRows(root, detailLimit) {
        const selector = '[class*="animate-"], [class*="backdrop-blur"], .sticky';

        return Array.from(root.document.querySelectorAll(selector))
            .slice(0, detailLimit)
            .map(el => describeAnimatedElement(root, el));
    }

    function collectAnimationGroups(rows, detailLimit) {
        const groups = new Map();

        for (const row of rows) {
            const key = [
                row.signature,
                row.nearestLivewireName || '',
                row.nearestTestId || '',
                row.nearestInteractiveSignature || '',
                row.nearestButtonLabel || '',
                row.nearestWireClick || '',
            ].join('|');
            const group = groups.get(key) || {
                signature: row.signature,
                count: 0,
                runningCount: 0,
                animationNames: [],
                classes: row.classes,
                nearestLivewireName: row.nearestLivewireName,
                nearestTestId: row.nearestTestId,
                nearestInteractiveSignature: row.nearestInteractiveSignature,
                nearestButtonLabel: row.nearestButtonLabel,
                nearestButtonText: row.nearestButtonText,
                nearestButtonTitle: row.nearestButtonTitle,
                nearestButtonRole: row.nearestButtonRole,
                nearestButtonDisabled: row.nearestButtonDisabled,
                nearestLoading: row.nearestLoading,
                nearestWireClick: row.nearestWireClick,
                nearestWireTarget: row.nearestWireTarget,
            };

            group.count++;
            group.runningCount += row.runningCount || 0;
            group.animationNames = uniqueStringList([
                ...group.animationNames,
                ...(row.animationNames || []),
            ], 12);

            groups.set(key, group);
        }

        return Array.from(groups.values())
            .sort((a, b) => (
                b.runningCount - a.runningCount
                || b.count - a.count
                || a.signature.localeCompare(b.signature)
            ))
            .slice(0, detailLimit);
    }

    function boundedLimit(value, fallback, maximum) {
        const limit = Number(value ?? fallback);

        if (!Number.isFinite(limit)) {
            return fallback;
        }

        return Math.min(maximum, Math.max(0, Math.round(limit)));
    }

    function collectAnimations(root) {
        const config = root.rfaDiagnosticsConfig || {};
        const detailLimit = boundedLimit(config.animationDetailLimit, DEFAULT_ANIMATION_DETAIL_LIMIT, 50);
        const classSummaryLimit = boundedLimit(config.animationClassSummaryLimit, DEFAULT_ANIMATION_CLASS_SUMMARY_LIMIT, 50);
        let animations = [];

        if (typeof root.document.getAnimations === 'function') {
            try {
                animations = root.document.getAnimations({ subtree: true }) || [];
            } catch {
                animations = [];
            }
        }

        const rows = animations.length > 0
            ? collectAnimationRows(root, animations, detailLimit)
            : fallbackAnimationRows(root, detailLimit);

        return {
            activeCount: animations.length || rows.length,
            runningCount: animations.filter(animation => animation.playState === 'running').length,
            cssAnimationCount: animations.filter(animation => animationKind(animation) === 'animation').length,
            cssTransitionCount: animations.filter(animation => animationKind(animation) === 'transition').length,
            classSummary: collectAnimationClassSummary(root.document, classSummaryLimit),
            elementGroups: collectAnimationGroups(rows, detailLimit),
            elements: rows,
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
            ...collectClassCounters(doc),
        };
    }

    function collectPoll(root) {
        const tick = root.__rfaLastSmartPollTick || null;

        if (!tick) {
            return null;
        }

        const ageMs = Math.max(0, Date.now() - tick.at);

        if (ageMs > RECENT_POLL_WINDOW_MS) {
            return null;
        }

        return {
            source: shortString(tick.source, 96),
            method: shortString(tick.method, 96),
            intervalMs: roundMs(tick.intervalMs),
            ageMs: roundMs(ageMs),
            hidden: !!tick.hidden,
            focused: !!tick.focused,
        };
    }

    function livewireComponentName(component) {
        return plainString(component?.name, 128) || plainString(component?.snapshot?.memo?.name, 128);
    }

    function collectLivewireCommit(root, status, elapsedMs, component, commit) {
        const calls = Array.isArray(commit?.calls) ? commit.calls : [];
        const updates = commit?.updates && typeof commit.updates === 'object' ? commit.updates : {};
        const poll = collectPoll(root);

        return {
            status,
            elapsedMs: roundMs(elapsedMs),
            componentId: shortString(component?.id || null, 64),
            componentName: livewireComponentName(component),
            callCount: calls.length,
            calls: stringList(calls.map(call => call?.method), 20),
            updateCount: Object.keys(updates).length,
            updateKeys: stringList(Object.keys(updates), 20),
            pollSource: poll?.source || null,
            pollMethod: poll?.method || null,
            pollAgeMs: poll?.ageMs ?? null,
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
            screen: collectScreen(root),
            visibility: collectVisibility(root),
            activity: collectActivity(root),
            scroll: collectScroll(root),
            heap: collectHeap(root),
            dom: collectDom(root.document),
            animations: collectAnimations(root),
            navigation: collectNavigation(root),
            poll: collectPoll(root),
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
        const commitSampleThrottleMs = Number(config.commitSampleThrottleMs ?? COMMIT_SAMPLE_THROTTLE_MS);
        const pendingDiffActions = new Map();
        let lastProcessSampleAt = 0;
        let lastCommitSampleAt = 0;

        function markFocusState() {
            const previous = root.__rfaFocusState || {};
            const focused = root.document.hasFocus();
            const hidden = root.document.hidden;
            const visibleAt = previous.hidden === hidden ? previous.visibleAt : Date.now();

            root.__rfaFocusState = {
                at: Date.now(),
                visibleAt: visibleAt || Date.now(),
                focused,
                hidden,
            };
        }

        function markActivity(type) {
            root.__rfaLastActivity = {
                at: Date.now(),
                type,
            };
        }

        markFocusState();
        markActivity('install');

        root.addEventListener('focus', markFocusState);
        root.addEventListener('blur', markFocusState);

        for (const eventName of ['pointerdown', 'keydown', 'wheel', 'scroll']) {
            root.addEventListener(eventName, () => markActivity(eventName), { passive: true });
        }

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

        root.document.addEventListener('visibilitychange', () => {
            markFocusState();
            sample('visibility');
        });
        root.document.addEventListener('livewire:navigated', () => sample('navigate', true));

        root.document.addEventListener('livewire:init', () => {
            if (!root.Livewire || root.__rfaRuntimeDiagnosticsLivewireHooked) {
                return;
            }

            root.__rfaRuntimeDiagnosticsLivewireHooked = true;
            root.Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
                const startedAtMs = nowMs(root);
                const startedLongTasks = collectLongTasks(root);

                const mark = (status) => {
                    const now = Date.now();

                    if (now - lastCommitSampleAt < commitSampleThrottleMs) {
                        return;
                    }

                    lastCommitSampleAt = now;
                    sample('livewire.commit', false, {
                        livewireCommit: collectLivewireCommit(root, status, nowMs(root) - startedAtMs, component, commit),
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

    return { bytesToMegabytes, collectSample, collectLongTasks, collectPoll, collectAnimations, install, autoInstall };
});

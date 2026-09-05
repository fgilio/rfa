// One shared hover tooltip for controls that carry a `data-rfa-tip` label.
//
// A Flux tooltip per control costs a custom element, a popover and an inline
// SVG each. The sidebar repeats its row controls per file, so at a few hundred
// files those dominate the page. Here each control carries one attribute and
// a single fixed-position bubble follows the pointer, so nothing clips it.
(function (root, factory) {
    const api = factory();

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else if (root) {
        root.rfaTooltip = api;
        api.install(root);
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const SHOW_DELAY_MS = 300;
    const GAP_PX = 6;
    const SELECTOR = '[data-rfa-tip]';

    function createTooltip(root, { delayMs = SHOW_DELAY_MS } = {}) {
        const doc = root.document;
        let bubble = null;
        let current = null;
        let timer = null;

        function ensureBubble() {
            if (bubble) return bubble;

            bubble = doc.createElement('div');
            bubble.className = 'rfa-tip-bubble';
            bubble.setAttribute('role', 'tooltip');
            bubble.hidden = true;
            doc.body.appendChild(bubble);

            return bubble;
        }

        function place(target) {
            const el = ensureBubble();
            const rect = target.getBoundingClientRect();
            const centerX = rect.left + rect.width / 2;

            el.textContent = target.getAttribute('data-rfa-tip') || '';
            el.hidden = false;
            el.style.left = `${centerX}px`;

            // Above the control, unless that would leave the viewport.
            const height = el.offsetHeight || 0;
            const above = rect.top - GAP_PX - height >= 0;

            el.dataset.placement = above ? 'above' : 'below';
            el.style.top = above ? `${rect.top - GAP_PX}px` : `${rect.bottom + GAP_PX}px`;
        }

        function show(target) {
            if (!target.isConnected || target.getAttribute('data-rfa-tip') === null) {
                hide();

                return;
            }

            current = target;
            place(target);
        }

        function hide() {
            root.clearTimeout(timer);
            timer = null;
            current = null;

            if (bubble) {
                bubble.hidden = true;
            }
        }

        function schedule(target, immediate = false) {
            if (target === current) return;

            hide();

            if (immediate) {
                show(target);

                return;
            }

            timer = root.setTimeout(() => show(target), delayMs);
        }

        function targetOf(event) {
            const node = event.target;

            return node && typeof node.closest === 'function' ? node.closest(SELECTOR) : null;
        }

        function onPointerOver(event) {
            const target = targetOf(event);

            if (!target) {
                if (timer !== null) hide();

                return;
            }

            schedule(target);
        }

        function onPointerOut(event) {
            const target = targetOf(event);

            if (!target) return;

            const next = event.relatedTarget;

            if (next && target.contains(next)) return;

            hide();
        }

        function onFocusIn(event) {
            const target = targetOf(event);

            if (target && typeof target.matches === 'function' && target.matches(':focus-visible')) {
                schedule(target, true);
            }
        }

        function onFocusOut(event) {
            if (targetOf(event)) hide();
        }

        function onDismiss() {
            hide();
        }

        function attach() {
            doc.addEventListener('mouseover', onPointerOver);
            doc.addEventListener('mouseout', onPointerOut);
            doc.addEventListener('focusin', onFocusIn);
            doc.addEventListener('focusout', onFocusOut);
            doc.addEventListener('mousedown', onDismiss, true);
            doc.addEventListener('keydown', onDismiss, true);
            doc.addEventListener('scroll', onDismiss, true);
        }

        function detach() {
            hide();
            doc.removeEventListener('mouseover', onPointerOver);
            doc.removeEventListener('mouseout', onPointerOut);
            doc.removeEventListener('focusin', onFocusIn);
            doc.removeEventListener('focusout', onFocusOut);
            doc.removeEventListener('mousedown', onDismiss, true);
            doc.removeEventListener('keydown', onDismiss, true);
            doc.removeEventListener('scroll', onDismiss, true);
        }

        return {
            attach,
            detach,
            hide,
            get bubble() {
                return bubble;
            },
            get current() {
                return current;
            },
        };
    }

    function install(root, options = {}) {
        if (root.__rfaTooltipAttached) return false;

        root.__rfaTooltipAttached = true;

        const tooltip = createTooltip(root, options);

        if (root.document.body) {
            tooltip.attach();
        } else {
            root.document.addEventListener('DOMContentLoaded', () => tooltip.attach(), { once: true });
        }

        return true;
    }

    return { createTooltip, install, SHOW_DELAY_MS };
});

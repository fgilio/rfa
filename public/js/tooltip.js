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

    function createTooltip(root) {
        const doc = root.document;
        let bubble = null;
        let bubbleHeight = 0;
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

            el.textContent = target.getAttribute('data-rfa-tip') || '';
            el.hidden = false;

            // The bubble is a single nowrap line, so its height is constant:
            // measure it once rather than forcing a layout on every show.
            if (bubbleHeight === 0) {
                bubbleHeight = el.offsetHeight || 0;
            }

            const above = rect.top - GAP_PX - bubbleHeight >= 0;

            el.dataset.placement = above ? 'above' : 'below';
            el.style.left = `${rect.left + rect.width / 2}px`;
            el.style.top = above ? `${rect.top - GAP_PX}px` : `${rect.bottom + GAP_PX}px`;
        }

        function show(target) {
            if (!target.isConnected || target.getAttribute('data-rfa-tip') === null) return;

            current = target;
            place(target);
        }

        function hide() {
            if (current === null && timer === null) return;

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

            timer = root.setTimeout(() => show(target), SHOW_DELAY_MS);
        }

        function targetOf(event) {
            const node = event.target;

            return node && typeof node.closest === 'function' ? node.closest(SELECTOR) : null;
        }

        function onPointerOver(event) {
            const target = targetOf(event);

            if (!target) {
                hide();

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

        const listeners = [
            ['mouseover', onPointerOver, false],
            ['mouseout', onPointerOut, false],
            ['focusin', onFocusIn, false],
            ['focusout', onFocusOut, false],
            ['mousedown', hide, { capture: true }],
            ['keydown', hide, { capture: true }],
            ['scroll', hide, { capture: true, passive: true }],
            // A navigation replaces the control under the pointer without a
            // mouseout, which would leave the bubble floating on the new page.
            ['livewire:navigating', hide, false],
        ];

        function attach() {
            listeners.forEach(([type, handler, options]) => doc.addEventListener(type, handler, options));
        }

        function detach() {
            hide();
            listeners.forEach(([type, handler, options]) => doc.removeEventListener(type, handler, options));
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

    function install(root) {
        if (root.__rfaTooltipAttached) return false;

        root.__rfaTooltipAttached = true;

        const tooltip = createTooltip(root);

        if (root.document.body) {
            tooltip.attach();
        } else {
            root.document.addEventListener('DOMContentLoaded', () => tooltip.attach(), { once: true });
        }

        return true;
    }

    return { createTooltip, install, SHOW_DELAY_MS };
});

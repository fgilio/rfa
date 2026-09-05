import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import tooltipModule from '../../public/js/tooltip.js';

const { createTooltip, install, SHOW_DELAY_MS } = tooltipModule;

function addControl(label, rect = { top: 200, bottom: 214, left: 100, right: 114, width: 14, height: 14 }) {
    const button = document.createElement('button');
    button.setAttribute('data-rfa-tip', label);
    button.getBoundingClientRect = vi.fn(() => rect);
    document.body.appendChild(button);

    return button;
}

function hover(target, relatedTarget = null) {
    target.dispatchEvent(new MouseEvent('mouseover', { bubbles: true, relatedTarget }));
}

function leave(target, relatedTarget = null) {
    target.dispatchEvent(new MouseEvent('mouseout', { bubbles: true, relatedTarget }));
}

describe('shared hover tooltip', () => {
    let tooltip;

    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '';
        tooltip = createTooltip(window);
        tooltip.attach();
    });

    afterEach(() => {
        tooltip.detach();
        vi.useRealTimers();
        document.body.innerHTML = '';
        delete window.__rfaTooltipAttached;
    });

    it('shows the label above the control after the hover delay', async () => {
        const control = addControl('Mark as reviewed');

        hover(control);
        expect(tooltip.bubble).toBeNull();

        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);

        expect(tooltip.bubble.hidden).toBe(false);
        expect(tooltip.bubble.textContent).toBe('Mark as reviewed');
        expect(tooltip.bubble.getAttribute('role')).toBe('tooltip');
        expect(tooltip.bubble.dataset.placement).toBe('above');
        expect(tooltip.bubble.style.left).toBe('107px');
        expect(tooltip.bubble.style.top).toBe('194px');
    });

    it('creates one bubble and reuses it across controls', async () => {
        const first = addControl('First');
        const second = addControl('Second');

        hover(first);
        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);
        const bubble = tooltip.bubble;

        leave(first, second);
        hover(second, first);
        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);

        expect(tooltip.bubble).toBe(bubble);
        expect(bubble.textContent).toBe('Second');
        expect(document.querySelectorAll('.rfa-tip-bubble')).toHaveLength(1);
    });

    it('does not show when the pointer leaves before the delay', async () => {
        const control = addControl('Discard changes');

        hover(control);
        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS / 2);
        leave(control, document.body);
        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);

        expect(tooltip.bubble).toBeNull();
    });

    it('hides when the pointer leaves the control', async () => {
        const control = addControl('Discard changes');

        hover(control);
        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);
        expect(tooltip.bubble.hidden).toBe(false);

        leave(control, document.body);

        expect(tooltip.bubble.hidden).toBe(true);
        expect(tooltip.current).toBeNull();
    });

    it('stays open while the pointer moves between the control and its icon', async () => {
        const control = addControl('Mark as reviewed');
        const icon = document.createElement('span');
        control.appendChild(icon);

        hover(control);
        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);

        leave(control, icon);
        hover(icon, control);

        expect(tooltip.bubble.hidden).toBe(false);
        expect(tooltip.current).toBe(control);
    });

    it('flips below the control when there is no room above', async () => {
        const control = addControl('Top row', { top: 4, bottom: 18, left: 100, right: 114, width: 14, height: 14 });

        hover(control);
        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);

        expect(tooltip.bubble.dataset.placement).toBe('below');
        expect(tooltip.bubble.style.top).toBe('24px');
    });

    it('dismisses on press, key, and scroll', async () => {
        for (const type of ['mousedown', 'keydown', 'scroll']) {
            const control = addControl(type);

            hover(control);
            await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);
            expect(tooltip.bubble.hidden).toBe(false);

            document.dispatchEvent(new Event(type, { bubbles: true }));

            expect(tooltip.bubble.hidden).toBe(true);
        }
    });

    it('does not show for a control removed before the delay elapses', async () => {
        const control = addControl('Gone');

        hover(control);
        control.remove();
        await vi.advanceTimersByTimeAsync(SHOW_DELAY_MS);

        expect(tooltip.bubble).toBeNull();
    });

    it('installs once per window', () => {
        expect(install(window)).toBe(true);
        expect(install(window)).toBe(false);
    });
});

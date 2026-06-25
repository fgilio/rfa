@props([
    'icon',
    'tooltip' => null,
    'ariaLabel' => null,
    'confirmLabel' => 'Confirm?',
    'duration' => 3000,
])

@php
    $resolvedAriaLabel = $ariaLabel ?? $tooltip ?? 'Action';
    $armedTooltip = 'Click again to confirm — cancels in '.(int) round($duration / 1000).'s';
@endphp

<div
    x-data="{
        armed: false,
        timer: null,
        arm() {
            this.armed = true;
            this.timer = setTimeout(() => this.disarm(), {{ (int) $duration }});
        },
        disarm() {
            if (! this.armed) return;
            this.armed = false;
            clearTimeout(this.timer);
            this.timer = null;
        },
        handle() {
            if (! this.armed) { this.arm(); return; }
            this.disarm();
            this.$dispatch('confirmed');
        },
        destroy() { clearTimeout(this.timer); },
    }"
    @keydown.escape.window="disarm()"
    @click.outside="disarm()"
    {{ $attributes->class('relative inline-flex') }}
>
    <flux:button
        variant="ghost"
        size="sm"
        x-bind:tooltip="armed ? @js($armedTooltip) : @js($tooltip)"
        x-bind:aria-label="armed ? @js($confirmLabel) : @js($resolvedAriaLabel)"
        x-bind:aria-pressed="armed"
        @click.stop.prevent="handle()"
        x-bind:class="armed && '!text-gh-red hover:!bg-gh-red/10'"
    >
        <flux:icon :icon="$icon" variant="outline" class="!size-4" />
    </flux:button>
    <div
        aria-hidden="true"
        class="pointer-events-none absolute inset-x-1 -bottom-px h-0.5 origin-left rounded-full bg-gh-red/70"
        x-bind:style="armed
            ? 'transform:scaleX(0); transition:transform {{ (int) $duration }}ms linear'
            : 'transform:scaleX(1); opacity:0; transition:none'"
    ></div>
    <span class="sr-only" aria-live="polite" x-text="armed ? @js($armedTooltip) : ''"></span>
</div>

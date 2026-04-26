@props([
    'confirm',
    'label' => null,
    'confirmLabel' => 'Confirm?',
    'icon' => null,
    'iconConfirm' => null,
    'tooltip' => null,
    'ariaLabel' => null,
    'variant' => 'ghost',
    'size' => 'sm',
    'duration' => 3000,
    'buttonClass' => '',
])

@php
    $resolvedIconConfirm = $iconConfirm ?? $icon;
    $resolvedAriaLabel = $ariaLabel ?? $label ?? 'Action';
    $armedTooltip = 'Click again to confirm — cancels in '.(int) round($duration / 1000).'s';
@endphp

<div
    x-data="{
        armed: false,
        timer: null,
        arm() { this.armed = true; this.timer = setTimeout(() => this.disarm(), {{ (int) $duration }}); },
        disarm() { this.armed = false; if (this.timer) { clearTimeout(this.timer); this.timer = null; } },
        handle() { if (!this.armed) { this.arm(); return; } this.disarm(); {{ $confirm }}; },
    }"
    @keydown.escape.window="disarm()"
    @click.outside="disarm()"
    {{ $attributes->only('class')->class('relative inline-flex') }}
>
    <flux:button
        variant="{{ $variant }}"
        size="{{ $size }}"
        x-bind:tooltip="armed ? @js($armedTooltip) : @js($tooltip)"
        x-bind:aria-label="armed ? @js($confirmLabel) : @js($resolvedAriaLabel)"
        @click.stop.prevent="handle()"
        x-bind:class="armed && '!text-red-500 dark:!text-red-400 hover:!bg-red-500/10'"
        class="{{ $buttonClass }}"
    >
        @if($icon)
            <flux:icon x-show="!armed" icon="{{ $icon }}" variant="outline" />
            <flux:icon x-show="armed" x-cloak icon="{{ $resolvedIconConfirm }}" variant="outline" />
        @endif
        @if($label !== null)
            <span x-text="armed ? @js($confirmLabel) : @js($label)"></span>
        @endif
    </flux:button>
    <div
        aria-hidden="true"
        class="pointer-events-none absolute inset-x-1 -bottom-px h-0.5 origin-left rounded-full bg-red-500/70"
        x-bind:style="armed
            ? 'transform:scaleX(0); transition:transform {{ (int) $duration }}ms linear'
            : 'transform:scaleX(1); opacity:0; transition:none'"
    ></div>
</div>

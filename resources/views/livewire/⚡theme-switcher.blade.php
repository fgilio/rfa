<?php

use Livewire\Component;

new class extends Component {};
?>

<div
    x-data="{
        setRfaCookie(name, value) {
            document.cookie = name + '=' + value + ';path=/;max-age=31536000;SameSite=Lax'
        },
    }"
    {{-- Electron reads the selected mode before the renderer exists. Keep the
         resolved cookie for other non-Livewire consumers. --}}
    x-effect="
        setRfaCookie('rfa_appearance', $flux.appearance);
        setRfaCookie('rfa_theme', $flux.dark ? 'dark' : 'light');
    "
>
    <flux:dropdown position="bottom" align="end">
        {{-- Trigger mirrors the current appearance: the icon swaps to whichever
             mode is active so the collapsed control always reads as "current mode".
             The accessible name tracks the same state so assistive tech announces
             the active theme without opening the menu (static label is the
             pre-hydration fallback). --}}
        <flux:button variant="ghost" size="sm" square data-testid="theme-switcher-trigger"
            aria-label="Theme"
            x-bind:aria-label="'Theme: ' + { light: 'Light', dark: 'Dark', system: 'System' }[$flux.appearance]">
            <flux:icon.sun variant="outline" class="size-5" x-show="$flux.appearance === 'light'" x-cloak />
            <flux:icon.moon variant="outline" class="size-5" x-show="$flux.appearance === 'dark'" x-cloak />
            <flux:icon.computer-desktop variant="outline" class="size-5" x-show="$flux.appearance === 'system'" x-cloak />
        </flux:button>

        {{-- Hug the content instead of Flux's default min-w-48, which leaves a
             wide empty gutter to the right of these short labels. --}}
        <flux:menu class="min-w-0">
            <flux:menu.radio.group x-model="$flux.appearance">
                <flux:menu.radio value="light">
                    <flux:icon.sun variant="outline" class="size-4 me-2.5" />
                    <span class="pe-2">Light</span>
                </flux:menu.radio>
                <flux:menu.radio value="dark">
                    <flux:icon.moon variant="outline" class="size-4 me-2.5" />
                    <span class="pe-2">Dark</span>
                </flux:menu.radio>
                <flux:menu.radio value="system">
                    <flux:icon.computer-desktop variant="outline" class="size-4 me-2.5" />
                    <span class="pe-2">System</span>
                </flux:menu.radio>
            </flux:menu.radio.group>
        </flux:menu>
    </flux:dropdown>
</div>

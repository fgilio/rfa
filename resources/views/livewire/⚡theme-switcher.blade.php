<?php

use Livewire\Component;

new class extends Component {};
?>

<div
    x-data
    {{-- Mirror the resolved theme into the rfa_theme cookie. $flux.dark is reactive
         to both explicit picks and OS changes while in system mode, so this single
         effect covers all three states without a separate matchMedia listener. --}}
    x-effect="document.cookie = 'rfa_theme=' + ($flux.dark ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax'"
>
    <flux:dropdown position="bottom" align="end">
        {{-- Trigger mirrors the current appearance: the icon swaps to whichever
             mode is active so the collapsed control always reads as "current mode". --}}
        <flux:button variant="ghost" size="sm" square aria-label="Theme" data-testid="theme-switcher-trigger">
            <flux:icon.sun variant="outline" class="size-5" x-show="$flux.appearance === 'light'" x-cloak />
            <flux:icon.moon variant="outline" class="size-5" x-show="$flux.appearance === 'dark'" x-cloak />
            <flux:icon.computer-desktop variant="outline" class="size-5" x-show="$flux.appearance === 'system'" x-cloak />
        </flux:button>

        <flux:menu>
            <flux:menu.radio.group x-model="$flux.appearance">
                <flux:menu.radio value="light">
                    <flux:icon.sun variant="outline" class="size-4 me-2" />
                    Light
                </flux:menu.radio>
                <flux:menu.radio value="dark">
                    <flux:icon.moon variant="outline" class="size-4 me-2" />
                    Dark
                </flux:menu.radio>
                <flux:menu.radio value="system">
                    <flux:icon.computer-desktop variant="outline" class="size-4 me-2" />
                    System
                </flux:menu.radio>
            </flux:menu.radio.group>
        </flux:menu>
    </flux:dropdown>
</div>

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
    <flux:radio.group x-model="$flux.appearance" variant="segmented" size="sm" aria-label="Theme">
        <flux:tooltip content="Light">
            <flux:radio value="light" icon="sun" icon:variant="outline" aria-label="Light theme" />
        </flux:tooltip>
        <flux:tooltip content="Dark">
            <flux:radio value="dark" icon="moon" icon:variant="outline" aria-label="Dark theme" />
        </flux:tooltip>
        <flux:tooltip content="Match system">
            <flux:radio value="system" icon="computer-desktop" icon:variant="outline" aria-label="Match system theme" />
        </flux:tooltip>
    </flux:radio.group>
</div>

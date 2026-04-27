<?php

use Livewire\Component;

new class extends Component {};
?>

<div
    x-data
    x-init="
        document.cookie = 'rfa_theme=' + (document.documentElement.classList.contains('dark') ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax';
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (!localStorage.getItem('flux.appearance')) {
                document.cookie = 'rfa_theme=' + (e.matches ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax';
            }
        });
    "
>
    <flux:tooltip content="Switch to dark mode">
        <flux:button
            x-on:click="$flux.dark = !$flux.dark; document.cookie = 'rfa_theme=' + ($flux.dark ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax'"
            variant="ghost" size="sm" aria-label="Switch to dark mode"
            icon="moon" icon:variant="outline" x-show="!$flux.dark" x-cloak
        />
    </flux:tooltip>
    <flux:tooltip content="Switch to light mode">
        <flux:button
            x-on:click="$flux.dark = !$flux.dark; document.cookie = 'rfa_theme=' + ($flux.dark ? 'dark' : 'light') + ';path=/;max-age=31536000;SameSite=Lax'"
            variant="ghost" size="sm" aria-label="Switch to light mode"
            icon="sun" icon:variant="outline" x-show="$flux.dark"
        />
    </flux:tooltip>
</div>

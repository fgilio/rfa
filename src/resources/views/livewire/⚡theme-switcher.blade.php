<?php

use Livewire\Component;

new class extends Component {};
?>

<div
    x-data="{ setCookie(theme) { document.cookie = 'rfa_theme=' + theme + ';path=/;max-age=31536000;SameSite=Lax' } }"
    x-init="
        setCookie(document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (!localStorage.getItem('flux.appearance')) {
                setCookie(e.matches ? 'dark' : 'light');
            }
        });
    "
>
    <flux:button
        x-on:click="$flux.dark = !$flux.dark; setCookie($flux.dark ? 'dark' : 'light')"
        variant="ghost" size="sm"
        icon="moon" icon:variant="outline" x-show="!$flux.dark" x-cloak
    />
    <flux:button
        x-on:click="$flux.dark = !$flux.dark; setCookie($flux.dark ? 'dark' : 'light')"
        variant="ghost" size="sm"
        icon="sun" icon:variant="outline" x-show="$flux.dark"
    />
</div>

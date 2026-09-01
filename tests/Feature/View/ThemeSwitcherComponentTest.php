<?php

use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

test('offers light, dark, and system as the three theme states', function () {
    Livewire::test('theme-switcher')
        ->assertSeeHtml('value="light"')
        ->assertSeeHtml('value="dark"')
        ->assertSeeHtml('value="system"');
});

test('binds the radio menu to the flux appearance state', function () {
    Livewire::test('theme-switcher')
        ->assertSeeHtml('x-model="$flux.appearance"')
        ->assertSeeHtml('data-flux-menu-radio-group');
});

test('collapses into a dropdown whose trigger reflects the current mode', function () {
    Livewire::test('theme-switcher')
        ->assertSeeHtml('data-testid="theme-switcher-trigger"')
        ->assertSeeHtml('data-flux-menu')
        ->assertSeeHtml("x-show=\"\$flux.appearance === 'light'\"")
        ->assertSeeHtml("x-show=\"\$flux.appearance === 'dark'\"")
        ->assertSeeHtml("x-show=\"\$flux.appearance === 'system'\"");
});

test('announces the active theme in the trigger accessible name', function () {
    Livewire::test('theme-switcher')
        ->assertSeeHtml("x-bind:aria-label=\"'Theme: ' + { light: 'Light', dark: 'Dark', system: 'System' }[\$flux.appearance]\"");
});

test('delegates selected appearance persistence to the startup store', function () {
    $html = Livewire::test('theme-switcher')
        ->assertSeeHtml('rfaAppearanceStore.persistSelectedAppearance')
        ->assertSeeHtml('$flux.appearance')
        ->html();

    expect($html)
        ->not->toContain('rfa_theme')
        ->not->toContain('document.cookie');
});

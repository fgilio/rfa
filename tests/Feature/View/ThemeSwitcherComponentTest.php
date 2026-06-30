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

test('binds the segmented control to the flux appearance state', function () {
    Livewire::test('theme-switcher')
        ->assertSeeHtml('x-model="$flux.appearance"')
        ->assertSeeHtml('data-flux-radio-group-segmented');
});

test('mirrors the resolved theme into the rfa_theme cookie', function () {
    Livewire::test('theme-switcher')
        ->assertSeeHtml('rfa_theme');
});

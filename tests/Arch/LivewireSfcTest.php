<?php

/**
 * Livewire SFC conventions:
 * - SFC components must extend Livewire\Component
 * - SFC components must import Livewire\Component
 * - SFC components must not import Services directly (delegate to Actions)
 * - SFC components must not import Models directly (delegate to Actions)
 * - Page SFCs live in resources/views/pages/
 * - Non-page SFCs live in resources/views/livewire/
 *
 * Uses raw glob()/file_get_contents() instead of File facade because
 * arch tests run without booting the app (no service container).
 */
beforeEach(function () {
    $resourceDir = dirname(__DIR__, 2).'/resources/views';

    $this->sfcFiles = collect([
        ...glob($resourceDir.'/pages/*.blade.php'),
        ...glob($resourceDir.'/livewire/*.blade.php'),
    ])->filter(fn (string $path) => str_contains(file_get_contents($path), 'extends Component'));
});

test('sfc components exist', function () {
    expect($this->sfcFiles)->not->toBeEmpty();
});

test('sfc components extend livewire component', function () {
    $this->sfcFiles->each(function (string $path) {
        $content = file_get_contents($path);
        expect($content)
            ->toContain('use Livewire\Component;')
            ->toContain('extends Component');
    });
});

test('sfc components do not import services directly', function () {
    $this->sfcFiles->each(function (string $path) {
        $content = file_get_contents($path);
        expect($content)->not->toContain('use App\Services\\');
    });
});

test('sfc components do not import models directly', function () {
    $this->sfcFiles->each(function (string $path) {
        $content = file_get_contents($path);
        expect($content)->not->toContain('use App\Models\\');
    });
});

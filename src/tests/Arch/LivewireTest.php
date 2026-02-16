<?php

/**
 * Livewire layer conventions:
 * - Livewire components must extend Livewire\Component
 * - Livewire components must be classes
 * - Livewire components must have a render() method
 * - Livewire components must not use Services directly (delegate to Actions)
 * - Livewire components must not use Eloquent Models directly (delegate to Actions)
 */

arch('livewire components are classes')
    ->expect('App\Livewire')
    ->toBeClasses();

arch('livewire components extend livewire component')
    ->expect('App\Livewire')
    ->toExtend('Livewire\Component');

arch('livewire components have a render method')
    ->expect('App\Livewire')
    ->toHaveMethod('render');

arch('livewire components do not use services directly')
    ->expect('App\Livewire')
    ->not->toUse('App\Services')
    ->ignoring('App\Livewire\DiffFile');

arch('livewire components do not use eloquent models directly')
    ->expect('App\Livewire')
    ->not->toUse('App\Models');

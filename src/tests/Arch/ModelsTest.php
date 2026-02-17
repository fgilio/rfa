<?php

/**
 * Models layer conventions:
 * - Models must extend Illuminate\Database\Eloquent\Model
 * - Models must be classes
 * - Models must not depend on Livewire
 * - Models must not depend on Actions or Services (no business logic)
 */
arch('models are classes')
    ->expect('App\Models')
    ->toBeClasses();

arch('models extend eloquent model')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('models do not depend on livewire')
    ->expect('App\Models')
    ->not->toUse('Livewire');

arch('models do not depend on actions')
    ->expect('App\Models')
    ->not->toUse('App\Actions');

arch('models do not depend on services')
    ->expect('App\Models')
    ->not->toUse('App\Services');

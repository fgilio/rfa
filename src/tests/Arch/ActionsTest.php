<?php

/**
 * Actions layer conventions:
 * - All Actions must be final readonly classes
 * - All Actions must have a handle() method
 * - Actions must have the "Action" suffix
 * - Actions must not depend on Livewire
 * - Actions must not depend on Illuminate\Http
 */
arch('actions are final readonly classes')
    ->expect('App\Actions')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

arch('actions have a handle method')
    ->expect('App\Actions')
    ->classes()
    ->toHaveMethod('handle');

arch('actions have the Action suffix')
    ->expect('App\Actions')
    ->classes()
    ->toHaveSuffix('Action');

arch('actions do not depend on livewire')
    ->expect('App\Actions')
    ->not->toUse('Livewire');

arch('actions do not depend on http layer')
    ->expect('App\Actions')
    ->not->toUse('Illuminate\Http');

arch('actions use strict types')
    ->expect('App\Actions')
    ->toUseStrictTypes();

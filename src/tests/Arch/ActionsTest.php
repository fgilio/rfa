<?php

/**
 * Actions layer conventions:
 * - All Actions must be final readonly classes
 * - All Actions must have a handle() method
 * - Actions must have the "Action" suffix (except DiffCacheKey which is a misplaced utility)
 * - Actions must not depend on Livewire
 * - Actions must not depend on Illuminate\Http
 */

arch('actions are final readonly classes')
    ->expect('App\Actions')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly()
    ->ignoring('App\Actions\DiffCacheKey');

arch('actions have a handle method')
    ->expect('App\Actions')
    ->classes()
    ->toHaveMethod('handle')
    ->ignoring('App\Actions\DiffCacheKey');

arch('actions have the Action suffix')
    ->expect('App\Actions')
    ->classes()
    ->toHaveSuffix('Action')
    ->ignoring('App\Actions\DiffCacheKey');

arch('actions do not depend on livewire')
    ->expect('App\Actions')
    ->not->toUse('Livewire');

arch('actions do not depend on http layer')
    ->expect('App\Actions')
    ->not->toUse('Illuminate\Http');

arch('actions use strict types')
    ->expect('App\Actions')
    ->toUseStrictTypes();

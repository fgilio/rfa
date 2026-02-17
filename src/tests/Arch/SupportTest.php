<?php

/**
 * Support layer conventions:
 * - Support classes are standalone utilities (cache keys, helpers, value objects)
 * - Support must not depend on Actions, Services, Models, Livewire, or Providers
 * - Support is accessible from any app layer (no toOnlyBeUsedIn restriction)
 */
arch('support uses strict types')
    ->expect('App\Support')
    ->toUseStrictTypes();

arch('support classes are final')
    ->expect('App\Support')
    ->toBeFinal();

arch('support does not depend on actions')
    ->expect('App\Support')
    ->not->toUse('App\Actions');

arch('support does not depend on services')
    ->expect('App\Support')
    ->not->toUse('App\Services');

arch('support does not depend on models')
    ->expect('App\Support')
    ->not->toUse('App\Models');

arch('support does not depend on livewire')
    ->expect('App\Support')
    ->not->toUse('App\Livewire');

arch('support does not depend on providers')
    ->expect('App\Support')
    ->not->toUse('App\Providers');

<?php

/**
 * Cross-layer dependency rules enforce the layered architecture:
 *
 *   Livewire → Actions → Services/DTOs → (nothing app-level)
 *                      → Models
 *
 * Key rules:
 * - DTOs must not depend on any other app layer
 * - Services must not depend on Actions or Livewire
 * - Actions must not depend on Livewire
 * - Models must not depend on any other app layer
 * - Only Actions should use Models (persistence boundary)
 */
arch('dtos are standalone and do not depend on other app layers')
    ->expect('App\DTOs')
    ->not->toUse([
        'App\Actions',
        'App\Services',
        'App\Models',
        'App\Livewire',
        'App\Providers',
    ]);

arch('services do not depend on actions')
    ->expect('App\Services')
    ->not->toUse('App\Actions');

arch('services do not depend on livewire')
    ->expect('App\Services')
    ->not->toUse('App\Livewire');

arch('actions do not depend on livewire')
    ->expect('App\Actions')
    ->not->toUse('App\Livewire');

arch('models do not depend on other app layers')
    ->expect('App\Models')
    ->not->toUse([
        'App\Actions',
        'App\Services',
        'App\DTOs',
        'App\Livewire',
    ]);

arch('models are only used in actions')
    ->expect('App\Models')
    ->toOnlyBeUsedIn('App\Actions');

arch('services are only used in actions and services')
    ->expect('App\Services')
    ->toOnlyBeUsedIn([
        'App\Actions',
        'App\Services',
    ]);

arch('dtos are only used in services and actions')
    ->expect('App\DTOs')
    ->toOnlyBeUsedIn([
        'App\Services',
        'App\Actions',
        'App\DTOs',
    ]);

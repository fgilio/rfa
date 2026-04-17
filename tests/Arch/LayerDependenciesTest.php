<?php

/**
 * Cross-layer dependency rules enforce the layered architecture:
 *
 *   Livewire → Actions → Services/DTOs → (nothing app-level)
 *                      → Models
 *   Observers → Models (and Actions)
 *   Support ← (any layer, standalone utilities)
 *
 * Key rules:
 * - DTOs must not depend on any other app layer
 * - Services must not depend on Actions or Livewire
 * - Actions must not depend on Livewire
 * - Models must not depend on any other app layer
 * - Only Actions, Observers, Factories, and console benchmark tooling should use Models
 */
arch('dtos are standalone and do not depend on other app layers')
    ->expect('App\DTOs')
    ->not->toUse([
        'App\Actions',
        'App\Services',
        'App\Models',
        'App\Livewire',
        'App\Providers',
        'App\Support',
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

arch('models are only used in actions, observers, other models, factories, and console benchmark tooling')
    ->expect('App\Models')
    ->toOnlyBeUsedIn([
        'App\Actions',
        'App\Observers',
        'App\Models',
        'Database\Factories',
        'App\Console\Benchmark',
    ]);

arch('services are only used in actions and services')
    ->expect('App\Services')
    ->toOnlyBeUsedIn([
        'App\Actions',
        'App\Services',
    ]);

arch('dtos are only used in services, actions, livewire, and console benchmark tooling')
    ->expect('App\DTOs')
    ->toOnlyBeUsedIn([
        'App\Services',
        'App\Actions',
        'App\DTOs',
        'App\Livewire',
        'App\Console\Benchmark',
    ]);

arch('support does not depend on other app layers')
    ->expect('App\Support')
    ->not->toUse([
        'App\Actions',
        'App\Services',
        'App\Models',
        'App\Livewire',
        'App\Providers',
    ]);

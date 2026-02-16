<?php

/**
 * DTOs layer conventions:
 * - DTOs must be classes
 * - DTOs must have a constructor (readonly properties set via constructor)
 * - DTOs must have a toArray() serialization method
 * - DTOs must not depend on Livewire, Http, or Eloquent
 * - DTOs must extend nothing (pure value objects)
 * - DTOs must implement nothing
 * - DTOs must use strict types
 */

arch('dtos are classes')
    ->expect('App\DTOs')
    ->toBeClasses();

arch('dtos have a constructor')
    ->expect('App\DTOs')
    ->toHaveConstructor();

arch('dtos have a toArray method')
    ->expect('App\DTOs')
    ->toHaveMethod('toArray');

arch('dtos extend nothing')
    ->expect('App\DTOs')
    ->toExtendNothing();

arch('dtos implement nothing')
    ->expect('App\DTOs')
    ->toImplementNothing();

arch('dtos do not depend on livewire')
    ->expect('App\DTOs')
    ->not->toUse('Livewire');

arch('dtos do not depend on eloquent')
    ->expect('App\DTOs')
    ->not->toUse('Illuminate\Database');

arch('dtos do not depend on http layer')
    ->expect('App\DTOs')
    ->not->toUse('Illuminate\Http');

arch('dtos use strict types')
    ->expect('App\DTOs')
    ->toUseStrictTypes();

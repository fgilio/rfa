<?php

/**
 * Services layer conventions:
 * - Services are plain classes (stateless domain operations)
 * - Services must have the "Service", "Parser", "Formatter", or "Exporter" suffix
 * - Services must not depend on Livewire
 * - Services must not depend on Eloquent Models directly
 * - Services must not depend on the Http layer
 */
arch('services are classes')
    ->expect('App\Services')
    ->toBeClasses();

arch('services do not depend on livewire')
    ->expect('App\Services')
    ->not->toUse('Livewire');

arch('services do not depend on http layer')
    ->expect('App\Services')
    ->not->toUse('Illuminate\Http');

arch('services do not use eloquent models directly')
    ->expect('App\Services')
    ->not->toUse('App\Models');

arch('services use strict types')
    ->expect('App\Services')
    ->toUseStrictTypes();

test('services have a conventional suffix', function () {
    $allowed = ['Service', 'Parser', 'Formatter', 'Exporter'];
    $dir = dirname(__DIR__, 2).'/app/Services';
    $files = glob($dir.'/*.php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $valid = collect($allowed)->contains(fn ($s) => str_ends_with($name, $s));
        expect($valid)->toBeTrue("{$name} must end with: ".implode(', ', $allowed));
    }
});

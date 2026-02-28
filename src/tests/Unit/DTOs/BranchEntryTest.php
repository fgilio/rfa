<?php

use App\DTOs\BranchEntry;

test('toArray returns all fields for local branch', function () {
    $entry = new BranchEntry(
        name: 'main',
        isCurrent: true,
        isRemote: false,
    );

    $array = $entry->toArray();

    expect($array)->toBe([
        'name' => 'main',
        'isCurrent' => true,
        'isRemote' => false,
        'remote' => null,
    ]);
});

test('toArray returns all fields for remote branch', function () {
    $entry = new BranchEntry(
        name: 'origin/feature-x',
        isCurrent: false,
        isRemote: true,
        remote: 'origin',
    );

    $array = $entry->toArray();

    expect($array)->toBe([
        'name' => 'origin/feature-x',
        'isCurrent' => false,
        'isRemote' => true,
        'remote' => 'origin',
    ]);
});

test('properties are readonly', function () {
    $entry = new BranchEntry(
        name: 'develop',
        isCurrent: false,
        isRemote: false,
    );

    $ref = new ReflectionProperty($entry, 'name');

    expect($ref->isReadOnly())->toBeTrue();
});

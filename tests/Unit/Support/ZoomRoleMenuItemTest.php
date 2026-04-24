<?php

declare(strict_types=1);

use App\Support\ZoomRoleMenuItem;

test('serializes zoom role with label and default accelerator', function () {
    $array = (new ZoomRoleMenuItem('zoomIn', 'Zoom In'))->toArray();

    expect($array)->toMatchArray([
        'type' => 'normal',
        'role' => 'zoomIn',
        'label' => 'Zoom In',
        'accelerator' => 'CommandOrControl+Plus',
    ]);
});

test('omits label when none is provided', function () {
    $array = (new ZoomRoleMenuItem('resetZoom'))->toArray();

    expect($array)
        ->type->toBe('normal')
        ->role->toBe('resetZoom')
        ->accelerator->toBe('CommandOrControl+0')
        ->not->toHaveKey('label');
});

test('uses default accelerator for zoomOut so ⌘- is bound explicitly', function () {
    // Regression: with type='role' the NativePHP helper strips the
    // accelerator and Electron's role-default ⌘- binding silently fails
    // on macOS for zoomOut. Emitting type='normal' with an explicit
    // accelerator lets the binding survive the compileMenu helper.
    $array = (new ZoomRoleMenuItem('zoomOut', 'Zoom Out'))->toArray();

    expect($array)
        ->type->toBe('normal')
        ->role->toBe('zoomOut')
        ->accelerator->toBe('CommandOrControl+-');
});

test('accepts an explicit accelerator override', function () {
    $array = (new ZoomRoleMenuItem('zoomIn', 'Zoom In', 'CommandOrControl+='))->toArray();

    expect($array)->accelerator->toBe('CommandOrControl+=');
});

test('omits accelerator for unknown role when none is provided', function () {
    $array = (new ZoomRoleMenuItem('unknown', 'Unknown'))->toArray();

    expect($array)
        ->type->toBe('normal')
        ->role->toBe('unknown')
        ->not->toHaveKey('accelerator');
});

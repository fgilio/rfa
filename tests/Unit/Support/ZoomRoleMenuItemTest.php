<?php

declare(strict_types=1);

use App\Support\ZoomRoleMenuItem;

test('uses default accelerator for known zoom roles', function (string $role, string $accelerator) {
    $array = (new ZoomRoleMenuItem($role, 'Some Label'))->toArray();

    expect($array)
        ->type->toBe('normal')
        ->role->toBe($role)
        ->accelerator->toBe($accelerator)
        ->label->toBe('Some Label');
})->with([
    'zoomIn' => ['zoomIn', 'CommandOrControl+Plus'],
    'zoomOut' => ['zoomOut', 'CommandOrControl+-'],
    'resetZoom' => ['resetZoom', 'CommandOrControl+0'],
]);

test('omits label when none is provided', function () {
    $array = (new ZoomRoleMenuItem('resetZoom'))->toArray();

    expect($array)
        ->role->toBe('resetZoom')
        ->not->toHaveKey('label');
});

test('accepts an explicit accelerator override', function () {
    $array = (new ZoomRoleMenuItem('zoomIn', 'Zoom In', 'CommandOrControl+='))->toArray();

    expect($array)->accelerator->toBe('CommandOrControl+=');
});

test('omits accelerator for unknown role when none is provided', function () {
    $array = (new ZoomRoleMenuItem('unknown', 'Unknown'))->toArray();

    expect($array)
        ->role->toBe('unknown')
        ->not->toHaveKey('accelerator');
});

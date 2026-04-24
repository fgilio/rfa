<?php

declare(strict_types=1);

use App\Support\ZoomRoleMenuItem;

test('serializes zoom role with label', function () {
    $array = (new ZoomRoleMenuItem('zoomIn', 'Zoom In'))->toArray();

    expect($array)->toMatchArray([
        'type' => 'role',
        'role' => 'zoomIn',
        'label' => 'Zoom In',
    ]);
});

test('omits label when none is provided', function () {
    $array = (new ZoomRoleMenuItem('resetZoom'))->toArray();

    expect($array['type'])->toBe('role');
    expect($array['role'])->toBe('resetZoom');
    expect($array)->not->toHaveKey('label');
});

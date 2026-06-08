<?php

use App\DTOs\ReviewConfig;

test('toArray returns review config values', function () {
    $config = new ReviewConfig(
        diffMaxBytes: 512_000,
        sourceMaxBytes: 1_048_576,
        cacheTtlHours: 24,
        defaultContextLines: 3,
        movedLineDetection: true,
        movedLineMode: 'zebra',
    );

    expect($config->toArray())->toBe([
        'diffMaxBytes' => 512_000,
        'sourceMaxBytes' => 1_048_576,
        'cacheTtlHours' => 24,
        'defaultContextLines' => 3,
        'movedLineDetection' => true,
        'movedLineMode' => 'zebra',
    ]);
});

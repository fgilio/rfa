<?php

use App\Services\ReviewConfigService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = new ReviewConfigService;
});

test('resolve reads effective config from config/rfa.php', function () {
    config([
        'rfa.diff_max_bytes' => 100,
        'rfa.source_max_bytes' => 200,
        'rfa.cache_ttl_hours' => 12,
        'rfa.default_context_lines' => 5,
        'rfa.moved_lines.enabled' => false,
        'rfa.moved_lines.mode' => 'blocks',
    ]);

    $config = $this->service->resolve();

    expect($config->toArray())->toBe([
        'diffMaxBytes' => 100,
        'sourceMaxBytes' => 200,
        'cacheTtlHours' => 12,
        'defaultContextLines' => 5,
        'movedLineDetection' => false,
        'movedLineMode' => 'blocks',
    ]);
});

test('resolve falls back for invalid integer config', function (string $key, mixed $value) {
    config([$key => $value]);

    $config = $this->service->resolve();

    expect($config->diffMaxBytes)->toBe(512_000)
        ->and($config->defaultContextLines)->toBe(3);
})->with([
    'zero diff bytes' => ['rfa.diff_max_bytes', 0],
    'negative context' => ['rfa.default_context_lines', -1],
    'non-numeric diff bytes' => ['rfa.diff_max_bytes', 'abc'],
]);

test('resolve falls back for invalid moved line config', function (string $key, mixed $value) {
    config([$key => $value]);

    $config = $this->service->resolve();

    expect($config->movedLineDetection)->toBeFalse()
        ->and($config->movedLineMode)->toBe('zebra');
})->with([
    'invalid boolean' => ['rfa.moved_lines.enabled', 'maybe'],
    'invalid mode' => ['rfa.moved_lines.mode', 'rainbow'],
    'array mode' => ['rfa.moved_lines.mode', ['blocks']],
]);

test('resolve memoizes the config for the request', function () {
    config(['rfa.diff_max_bytes' => 123]);

    $first = $this->service->resolve();

    config(['rfa.diff_max_bytes' => 456]);

    expect($this->service->resolve())->toBe($first)
        ->and($first->diffMaxBytes)->toBe(123);
});

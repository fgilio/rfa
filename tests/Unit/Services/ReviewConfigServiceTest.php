<?php

use App\Services\ReviewConfigService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->service = new ReviewConfigService;
});

test('resolve uses application defaults', function () {
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

test('repo settings override user settings and runtime overrides repo settings', function () {
    $config = $this->service->resolve(
        userSettings: [
            'diffMaxBytes' => 100,
            'sourceMaxBytes' => 200,
            'cacheTtlHours' => 10,
            'defaultContextLines' => 2,
        ],
        repoSettings: [
            'diffMaxBytes' => 300,
            'defaultContextLines' => 4,
        ],
        runtimeOverrides: [
            'diffMaxBytes' => 400,
            'movedLineDetection' => true,
            'movedLineMode' => 'dimmed-zebra',
        ],
    );

    expect($config->diffMaxBytes)->toBe(400)
        ->and($config->sourceMaxBytes)->toBe(200)
        ->and($config->cacheTtlHours)->toBe(10)
        ->and($config->defaultContextLines)->toBe(4)
        ->and($config->movedLineDetection)->toBeTrue()
        ->and($config->movedLineMode)->toBe('dimmed-zebra');
});

test('resolve accepts snake case settings from persisted storage', function () {
    $config = $this->service->resolve(repoSettings: [
        'diff_max_bytes' => 321,
        'source_max_bytes' => 654,
        'cache_ttl_hours' => 48,
        'default_context_lines' => 7,
        'moved_line_detection' => 'false',
        'moved_line_mode' => 'plain',
    ]);

    expect($config->toArray())->toMatchArray([
        'diffMaxBytes' => 321,
        'sourceMaxBytes' => 654,
        'cacheTtlHours' => 48,
        'defaultContextLines' => 7,
        'movedLineDetection' => false,
        'movedLineMode' => 'plain',
    ]);
});

test('resolve falls back for invalid integer settings', function (array $settings) {
    $config = $this->service->resolve(runtimeOverrides: $settings);

    expect($config->diffMaxBytes)->toBe(512_000)
        ->and($config->defaultContextLines)->toBe(3);
})->with([
    'zero diff bytes' => [['diffMaxBytes' => 0]],
    'negative context' => [['defaultContextLines' => -1]],
]);

test('resolve falls back for invalid moved line settings', function (array $settings) {
    $config = $this->service->resolve(runtimeOverrides: $settings);

    expect($config->movedLineDetection)->toBeFalse()
        ->and($config->movedLineMode)->toBe('zebra');
})->with([
    'invalid boolean' => [['movedLineDetection' => 'maybe']],
    'invalid mode' => [['movedLineMode' => 'rainbow']],
    'array mode' => [['movedLineMode' => ['blocks']]],
    'array mode via alias' => [['moved_line_mode' => ['zebra']]],
]);

test('resolve memoizes default config for the request', function () {
    config(['rfa.diff_max_bytes' => 123]);

    $first = $this->service->resolve();

    config(['rfa.diff_max_bytes' => 456]);

    expect($this->service->resolve())->toBe($first)
        ->and($first->diffMaxBytes)->toBe(123);
});

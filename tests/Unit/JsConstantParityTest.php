<?php

use App\DTOs\DiffTarget;
use App\Enums\BranchBaseState;
use App\Enums\BranchBaseUnavailableReason;
use App\Http\Requests\BrowserDiagnosticSampleRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/**
 * branch-explorer.js can't import PHP constants, so it carries its own copy of
 * the empty-tree hash. Guard the two against drift: the "Since the beginning"
 * row navigates with the JS value, but restore and diffing use the PHP one.
 */
test('branch-explorer.js EMPTY_TREE_HASH matches the PHP constant', function () {
    $js = File::get(base_path('public/js/branch-explorer.js'));

    expect($js)->toMatch("/EMPTY_TREE_HASH\s*=\s*'".preg_quote(DiffTarget::EMPTY_TREE_HASH, '/')."'/");
});

test('branch-explorer.js branch-base enums match the PHP enums', function () {
    $js = File::get(base_path('public/js/branch-explorer.js'));

    $valuesFor = function (string $name) use ($js): array {
        preg_match('/const '.preg_quote($name, '/').' = Object\.freeze\(\{(?<body>.*?)\}\);/s', $js, $enum);
        preg_match_all("/^\\s+\\w+:\\s+'([^']+)',/m", $enum['body'] ?? '', $values);

        return $values[1];
    };

    expect($valuesFor('BranchBaseState'))->toBe(
        array_map(fn (BranchBaseState $state): string => $state->value, BranchBaseState::cases()),
    )->and($valuesFor('BranchBaseUnavailableReason'))->toBe(
        array_map(
            fn (BranchBaseUnavailableReason $reason): string => $reason->value,
            BranchBaseUnavailableReason::cases(),
        ),
    );
});

/**
 * runtime-diagnostics.js is the only producer of browser diagnostic samples, and
 * BrowserDiagnosticSampleRequest rejects any field it does not name. A sample
 * field added on one side alone turns every heartbeat into a 422, so the two
 * lists have to agree.
 */
test('runtime-diagnostics.js posts the fields the diagnostics request accepts', function () {
    $js = File::get(base_path('public/js/runtime-diagnostics.js'));

    expect($js)->toMatch('/function collectSample\(/');

    $sample = Str::of($js)
        ->after('function collectSample(')
        ->after('return {')
        ->before("\n        };");

    preg_match_all('/^ {12}(\w+)[,:]/m', (string) $sample, $matches);

    $accepted = collect(array_keys((new BrowserDiagnosticSampleRequest)->rules()))
        ->reject(fn (string $field): bool => str_contains($field, '.'))
        ->sort()
        ->values()
        ->all();

    expect(collect($matches[1])->sort()->values()->all())->toBe($accepted);
});

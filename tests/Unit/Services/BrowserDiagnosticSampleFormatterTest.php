<?php

use App\Services\BrowserDiagnosticSampleFormatter;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->formatter = new BrowserDiagnosticSampleFormatter;
});

test('reduces the url to a redacted path and a hash of the real one', function (string $url, ?string $path) {
    $projected = $this->formatter->format(['url' => $url]);

    expect($projected['path'])->toBe($path)
        ->and($projected['path_hash'])->toBe($path === null ? null : hash('xxh128', parse_url($url, PHP_URL_PATH)));
})->with([
    ['http://127.0.0.1:8100/p/rfa/context?token=secret', '/p/{project}/context'],
    ['http://127.0.0.1:8100/p/rfa/c/abcdef', '/p/{project}/c/{hash}'],
    ['http://127.0.0.1:8100/p/rfa/r/aaaaaa..bbbbbb', '/p/{project}/r/{range}'],
    ['http://127.0.0.1:8100/p/rfa/rw/aaaaaa^', '/p/{project}/rw/{range}'],
    ['?token=secret', null],
]);

test('names an unreported reason', function () {
    expect($this->formatter->format([])['reason'])->toBe('unknown');
});

test('drops the timing sections the renderer left out', function () {
    $projected = $this->formatter->format([
        'timings' => [
            'longTasks' => ['count' => 2, 'totalMs' => 120, 'maxMs' => 80],
            'diffAction' => null,
            'livewireCommit' => null,
        ],
    ]);

    expect($projected['timings'])->toBe(['longTasks' => ['count' => 2, 'totalMs' => 120, 'maxMs' => 80]]);
});

test('caps animation detail to the configured limits', function () {
    config([
        'rfa.diagnostics.animation_detail_limit' => 2,
        'rfa.diagnostics.animation_class_summary_limit' => 1,
    ]);

    $projected = $this->formatter->format([
        'animations' => [
            'activeCount' => 9,
            'classSummary' => array_map(fn (int $index): array => ['name' => "class-{$index}", 'count' => $index], range(1, 5)),
            'elementGroups' => array_map(fn (int $index): array => ['signature' => "group-{$index}"], range(1, 5)),
            'elements' => array_map(fn (int $index): array => ['signature' => "element-{$index}"], range(1, 5)),
        ],
    ]);

    expect($projected['animations']['activeCount'])->toBe(9)
        ->and($projected['animations']['classSummary'])->toHaveCount(1)
        ->and($projected['animations']['elementGroups'])->toHaveCount(2)
        ->and($projected['animations']['elements'])->toHaveCount(2);
});

test('drops empty cells from animation rows', function () {
    $projected = $this->formatter->format([
        'animations' => [
            'elements' => [
                ['signature' => 'svg.animate-spin', 'maxDurationMs' => null, 'classes' => []],
                ['signature' => null, 'classes' => []],
            ],
        ],
    ]);

    expect($projected['animations']['elements'])->toBe([['signature' => 'svg.animate-spin']]);
});

test('reports no animations when the sample carries none', function () {
    expect($this->formatter->format(['animations' => []])['animations'])->toBeNull();
});

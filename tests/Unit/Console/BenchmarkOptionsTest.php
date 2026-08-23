<?php

use App\Console\Benchmark\BenchmarkOptions;

/**
 * @param  array<string, mixed>  $overrides
 * @param  list<string>  $scenarios
 */
function benchmarkOptions(array $overrides = [], array $scenarios = ['diff-small', 'diff-large']): BenchmarkOptions
{
    return BenchmarkOptions::fromOptions([
        'child' => false,
        'json' => false,
        'snapshot' => null,
        'compare' => null,
        'samples' => '5',
        'warmup-samples' => '1',
        'rounds' => '7',
        'warmup-rounds' => '2',
        'only' => [],
        'max-regression' => '5',
        'min-absolute-ms' => '1',
        'max-memory-regression' => '10',
        'min-absolute-memory-mb' => '3',
        'max-retained-memory-regression' => '10',
        'min-absolute-retained-memory-mb' => '3',
        ...$overrides,
    ], $scenarios);
}

test('parses the command input into typed values', function () {
    $options = benchmarkOptions([
        'json' => true,
        'snapshot' => '/tmp/rfa-perf.json',
        'samples' => '3',
        'only' => ['diff-small'],
        'min-absolute-ms' => '15',
    ]);

    expect($options->json)->toBeTrue()
        ->and($options->snapshotPath)->toBe('/tmp/rfa-perf.json')
        ->and($options->samples)->toBe(3)
        ->and($options->only)->toBe(['diff-small'])
        ->and($options->thresholds['median_ms']->minimumAbsoluteIncrease)->toBe(15.0);
});

test('builds the child process arguments from the validated value', function () {
    expect(benchmarkOptions(['rounds' => '3', 'warmup-rounds' => '0', 'only' => ['diff-small', 'diff-large']])->childArguments())
        ->toBe(['--child', '--json', '--rounds=3', '--warmup-rounds=0', '--only=diff-small', '--only=diff-large']);
});

test('requires at least one measured sample and round', function (string $option) {
    benchmarkOptions([$option => '0']);
})->with(['samples', 'rounds'])->throws(InvalidArgumentException::class, 'must be a whole number of 1 or more');

test('accepts skipping the warmup', function () {
    $options = benchmarkOptions(['warmup-samples' => '0', 'warmup-rounds' => '0']);

    expect($options->warmupSamples)->toBe(0)
        ->and($options->warmupRounds)->toBe(0);
});

test('rejects a sample count that is not a whole number', function () {
    benchmarkOptions(['samples' => '2.5']);
})->throws(InvalidArgumentException::class, 'The --samples option must be a whole number of 1 or more. Received: 2.5.');

test('rejects a negative threshold', function (string $option) {
    benchmarkOptions([$option => '-1000']);
})->with([
    'max-regression',
    'min-absolute-ms',
    'max-memory-regression',
    'min-absolute-memory-mb',
    'max-retained-memory-regression',
    'min-absolute-retained-memory-mb',
])->throws(InvalidArgumentException::class, 'must be a number of 0 or more');

test('rejects an unknown scenario', function () {
    benchmarkOptions(['only' => ['diff-small', 'diff-enormous']]);
})->throws(InvalidArgumentException::class, 'Unknown benchmark scenario [diff-enormous]. Available scenarios: diff-small, diff-large');

test('rejects a child run that also writes a snapshot', function () {
    benchmarkOptions(['child' => true, 'snapshot' => '/tmp/rfa-perf.json']);
})->throws(InvalidArgumentException::class, 'cannot write a snapshot');

test('rejects a child run that also compares', function () {
    benchmarkOptions(['child' => true, 'compare' => '/tmp/rfa-perf.json']);
})->throws(InvalidArgumentException::class, 'cannot compare against a snapshot');

test('rejects json output while comparing', function () {
    benchmarkOptions(['json' => true, 'compare' => '/tmp/rfa-perf.json']);
})->throws(InvalidArgumentException::class, 'has no output to produce');

test('rejects comparing a run against the snapshot it just wrote', function () {
    benchmarkOptions(['snapshot' => '/tmp/rfa-perf.json', 'compare' => '/tmp/rfa-perf.json']);
})->throws(InvalidArgumentException::class, 'both point at /tmp/rfa-perf.json');

test('writing a snapshot and comparing against another one stays allowed', function () {
    $options = benchmarkOptions(['snapshot' => '/tmp/rfa-perf-head.json', 'compare' => '/tmp/rfa-perf-base.json']);

    expect($options->snapshotPath)->toBe('/tmp/rfa-perf-head.json')
        ->and($options->comparePath)->toBe('/tmp/rfa-perf-base.json');
});

test('gates each metric with its own threshold pair', function () {
    $thresholds = benchmarkOptions(['max-memory-regression' => '25', 'min-absolute-memory-mb' => '4'])->thresholds;

    expect(array_keys($thresholds))->toBe(['median_ms', 'median_peak_mb', 'median_retained_mb'])
        ->and($thresholds['median_peak_mb']->maxRegression)->toBe(25.0)
        ->and($thresholds['median_peak_mb']->minimumAbsoluteIncrease)->toBe(4.0);
});

test('a metric only regresses once it clears both bars', function () {
    $threshold = benchmarkOptions()->thresholds['median_ms'];

    expect($threshold->regressed(100.0, 100.5, 0.5))->toBeFalse()
        ->and($threshold->regressed(100.0, 106.0, 6.0))->toBeTrue()
        ->and($threshold->regressed(1.0, 1.1, 10.0))->toBeFalse();
});

test('reports the configuration the run measured with', function () {
    expect(benchmarkOptions(['samples' => '3', 'max-regression' => '5'])->reportConfig())
        ->toBe([
            'samples' => 3,
            'warmup_samples' => 1,
            'rounds' => 7,
            'warmup_rounds' => 2,
            'max_regression' => 5.0,
            'max_memory_regression' => 10.0,
            'max_retained_memory_regression' => 10.0,
        ]);
});

<?php

use App\Console\Benchmark\PerfScenarioRunner;

test('benchmark runner reports all representative scenarios', function () {
    $results = app(PerfScenarioRunner::class)->measureAll(rounds: 1, warmupRounds: 0);

    expect(array_keys($results))->toBe([
        'diff-small',
        'diff-large',
        'diff-with-comments',
        'review-page-20-files',
        'review-page-50-files',
        'review-page-100-files',
        'flux-500-mixed',
        'flux-2000-mixed',
        'flux-500-nested',
    ]);
});

test('benchmark runner returns positive timings', function () {
    $results = app(PerfScenarioRunner::class)->measureAll(rounds: 1, warmupRounds: 0);

    foreach ($results as $scenario => $milliseconds) {
        expect($milliseconds, $scenario)->toBeFloat()->toBeGreaterThan(0);
    }
});

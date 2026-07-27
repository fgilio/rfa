<?php

use App\Console\Benchmark\PerfScenarioRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('benchmark runner reports all representative scenarios', function () {
    $results = app(PerfScenarioRunner::class)->measureAll(rounds: 1, warmupRounds: 0);

    expect(array_keys($results))->toBe([
        'diff-small',
        'diff-large',
        'diff-with-comments',
        'drawer-reply-filter',
        'load-file-diff-blade-default-context',
        'load-file-diff-blade-full-context',
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

    foreach ($results as $scenario => $measurement) {
        expect($measurement['median_ms'], $scenario)->toBeFloat()->toBeGreaterThan(0)
            ->and($measurement['median_peak_mb'], $scenario)->toBeFloat()->toBeGreaterThanOrEqual(0)
            ->and($measurement['median_retained_mb'], $scenario)->toBeFloat();
    }
});

test('benchmark runner preserves targeted scenario order', function () {
    $results = app(PerfScenarioRunner::class)->measureAll(
        rounds: 1,
        warmupRounds: 0,
        only: ['diff-large', 'diff-small'],
    );

    expect(array_keys($results))->toBe(['diff-large', 'diff-small']);
});

test('drawer reply filter fixtures are seeded once outside measured rounds', function () {
    $replyInsertCount = 0;

    DB::listen(function (QueryExecuted $query) use (&$replyInsertCount): void {
        if (Str::contains(Str::lower($query->sql), 'insert into "comment_replies"')) {
            $replyInsertCount++;
        }
    });

    app(PerfScenarioRunner::class)->measureAll(
        rounds: 2,
        warmupRounds: 1,
        only: ['drawer-reply-filter'],
    );

    expect($replyInsertCount)->toBe(100);
});

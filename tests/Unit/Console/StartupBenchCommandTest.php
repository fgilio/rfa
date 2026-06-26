<?php

use App\Console\Commands\StartupBenchCommand;

test('summarize doubles the boot cost and totals the launch sequence', function () {
    $report = (new StartupBenchCommand)->summarize(
        stockBoot: 200.0,
        patchedBoot: 120.0,
        stockCache: 1500.0,
        patchedCache: 120.0,
        opcacheAvailable: true,
    );

    expect($report['opcache_available'])->toBeTrue()
        // boot phase is counted twice (native:php-ini + native:config)
        ->and($report['rows'][0]['stock_ms'])->toBe(400.0)
        ->and($report['rows'][0]['patched_ms'])->toBe(240.0)
        ->and($report['rows'][1]['stock_ms'])->toBe(1500.0)
        ->and($report['rows'][1]['patched_ms'])->toBe(120.0)
        ->and($report['stock_total_ms'])->toBe(1900.0)
        ->and($report['patched_total_ms'])->toBe(360.0)
        ->and($report['saved_ms'])->toBe(1540.0);
});

test('summarize surfaces when opcache is unavailable', function () {
    $report = (new StartupBenchCommand)->summarize(200.0, 200.0, 1500.0, 1500.0, false);

    expect($report['opcache_available'])->toBeFalse()
        // With no opcache the patched path saves only the optimize → config:cache step.
        ->and($report['saved_ms'])->toBe(0.0);
});

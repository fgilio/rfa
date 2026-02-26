<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Browsable;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, Browsable::class)
    ->in('Browser');

uses(TestCase::class, RefreshDatabase::class)
    ->in('Unit/Perf');

expect()->extend('toRenderWithin', function (float $maxMs) {
    $ms = $this->value;
    $testName = test()->name();

    $line = "[PERF] {$testName}: {$ms}ms\n";
    fwrite(STDERR, $line);

    // Also append to log file when PERF_LOG env is set
    if ($logFile = env('PERF_LOG')) {
        file_put_contents($logFile, $line, FILE_APPEND);
    }

    expect($ms)->toBeLessThan($maxMs, "Render took {$ms}ms, exceeding {$maxMs}ms threshold");

    return $this;
});

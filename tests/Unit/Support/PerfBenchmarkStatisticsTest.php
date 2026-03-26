<?php

use App\Console\Benchmark\PerfBenchmarkStatistics;

test('median returns the middle value for odd-length samples', function () {
    expect(PerfBenchmarkStatistics::median([5.0, 1.0, 3.0]))->toBe(3.0);
});

test('median averages the two middle values for even-length samples', function () {
    expect(PerfBenchmarkStatistics::median([1.0, 2.0, 4.0, 8.0]))->toBe(3.0);
});

test('filterOutliers removes iqr outliers when enough samples exist', function () {
    expect(PerfBenchmarkStatistics::filterOutliers([10.0, 10.2, 10.3, 10.4, 25.0]))
        ->toBe([10.0, 10.2, 10.3, 10.4]);
});

test('percentageChange returns the relative delta from baseline', function () {
    expect(PerfBenchmarkStatistics::percentageChange(100.0, 107.5))->toBe(7.5);
});

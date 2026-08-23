<?php

declare(strict_types=1);

namespace App\Console\Benchmark;

/**
 * The regression policy for one benchmark metric.
 *
 * A run fails only when it clears both bars, so a percentage swing on a metric
 * small enough to be measurement noise does not fail the build.
 */
final readonly class MetricThreshold
{
    public function __construct(
        public float $maxRegression,
        public float $minimumAbsoluteIncrease,
    ) {}

    public function regressed(float $baseline, float $current, float $change): bool
    {
        return $change > $this->maxRegression
            && ($current - $baseline) >= $this->minimumAbsoluteIncrease;
    }
}

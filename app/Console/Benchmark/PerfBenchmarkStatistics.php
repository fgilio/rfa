<?php

declare(strict_types=1);

namespace App\Console\Benchmark;

final class PerfBenchmarkStatistics
{
    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function filterOutliers(array $values): array
    {
        sort($values);

        if (count($values) < 4) {
            return $values;
        }

        $firstQuartile = self::percentile($values, 25);
        $thirdQuartile = self::percentile($values, 75);
        $interQuartileRange = $thirdQuartile - $firstQuartile;
        $lowerBound = $firstQuartile - (1.5 * $interQuartileRange);
        $upperBound = $thirdQuartile + (1.5 * $interQuartileRange);

        $filtered = array_values(array_filter(
            $values,
            fn (float $value): bool => $value >= $lowerBound && $value <= $upperBound,
        ));

        return $filtered === [] ? $values : $filtered;
    }

    /**
     * @param  list<float>  $values
     */
    public static function median(array $values): float
    {
        sort($values);

        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    public static function percentageChange(float $baseline, float $current): float
    {
        if ($baseline <= 0.0) {
            return 0.0;
        }

        return (($current - $baseline) / $baseline) * 100;
    }

    /**
     * @param  list<float>  $values
     */
    private static function percentile(array $values, float $percentile): float
    {
        $count = count($values);

        if ($count === 1) {
            return $values[0];
        }

        $position = ($percentile / 100) * ($count - 1);
        $lowerIndex = (int) floor($position);
        $upperIndex = (int) ceil($position);

        if ($lowerIndex === $upperIndex) {
            return $values[$lowerIndex];
        }

        $weight = $position - $lowerIndex;

        return $values[$lowerIndex] + (($values[$upperIndex] - $values[$lowerIndex]) * $weight);
    }
}

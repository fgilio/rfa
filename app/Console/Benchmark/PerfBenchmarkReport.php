<?php

declare(strict_types=1);

namespace App\Console\Benchmark;

use RuntimeException;

/**
 * The wire format of `rfa:benchmark-perf` reports.
 *
 * A benchmark report is only useful if a missing number is loud: decoding a
 * partial report leniently turns a crashed child process or a truncated
 * snapshot into a scenario that looks 100% faster. Every metric this codec
 * reads is required and numeric, or the run fails with the scenario named.
 *
 * @phpstan-type ScenarioMetrics array{median_ms: float, median_peak_mb: float, median_retained_mb: float}
 * @phpstan-type BaselineMetrics array{median_ms: float, median_peak_mb: float|null, median_retained_mb: float|null}
 */
final class PerfBenchmarkReport
{
    public const SCHEMA_VERSION = 1;

    /** Snapshots written before the schema carried a version. */
    private const UNVERSIONED_SCHEMA = 0;

    /** @var list<string> */
    private const METRICS = ['median_ms', 'median_peak_mb', 'median_retained_mb'];

    /**
     * @param  array<string, mixed>  $report
     */
    public static function encode(array $report): string
    {
        return (string) json_encode(
            ['schema_version' => self::SCHEMA_VERSION, ...$report],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array{generated_at: string, results: array<string, ScenarioMetrics>}
     */
    public static function decodeChildSample(string $output): array
    {
        $payload = json_decode(trim($output), true);

        throw_unless(
            is_array($payload),
            RuntimeException::class,
            'The benchmark child process did not print a JSON report.',
        );

        self::assertSchemaVersion($payload['schema_version'] ?? null, 'benchmark child process report');

        $results = $payload['results'] ?? null;

        throw_unless(
            is_array($results),
            RuntimeException::class,
            'The benchmark child process report is missing its results.',
        );

        $measurements = [];

        foreach ($results as $scenario => $measurement) {
            $measurements[(string) $scenario] = self::metrics(
                $measurement,
                (string) $scenario,
                'benchmark child process report',
            );
        }

        return [
            'generated_at' => (string) ($payload['generated_at'] ?? ''),
            'results' => $measurements,
        ];
    }

    /**
     * @return array{schema_version: int, results: array<string, BaselineMetrics>}
     */
    public static function decodeSnapshot(string $path): array
    {
        throw_unless(is_file($path), RuntimeException::class, "Benchmark snapshot not found: {$path}");

        $snapshot = json_decode((string) file_get_contents($path), true);

        throw_unless(is_array($snapshot), RuntimeException::class, "Benchmark snapshot is not valid JSON: {$path}");

        $version = self::assertSchemaVersion(
            $snapshot['schema_version'] ?? self::UNVERSIONED_SCHEMA,
            "benchmark snapshot {$path}",
        );

        $results = $snapshot['results'] ?? null;

        throw_unless(is_array($results), RuntimeException::class, "Benchmark snapshot is missing its results: {$path}");

        $baselines = [];

        foreach ($results as $scenario => $result) {
            $baselines[(string) $scenario] = $version === self::UNVERSIONED_SCHEMA
                ? self::unversionedMetrics($result, (string) $scenario, $path)
                : self::metrics($result, (string) $scenario, "benchmark snapshot {$path}");
        }

        return ['schema_version' => $version, 'results' => $baselines];
    }

    private static function assertSchemaVersion(mixed $version, string $source): int
    {
        throw_unless(
            in_array($version, [self::UNVERSIONED_SCHEMA, self::SCHEMA_VERSION], true),
            RuntimeException::class,
            sprintf(
                'Unsupported schema version [%s] in the %s. This build reads version %d and unversioned reports.',
                is_scalar($version) ? (string) $version : get_debug_type($version),
                $source,
                self::SCHEMA_VERSION,
            ),
        );

        return (int) $version;
    }

    /**
     * @return ScenarioMetrics
     */
    private static function metrics(mixed $result, string $scenario, string $source): array
    {
        throw_unless(
            is_array($result),
            RuntimeException::class,
            "Scenario [{$scenario}] in the {$source} is not a set of metrics.",
        );

        $metrics = [];

        foreach (self::METRICS as $metric) {
            $value = $result[$metric] ?? null;

            throw_unless(
                is_numeric($value),
                RuntimeException::class,
                "Scenario [{$scenario}] in the {$source} is missing a numeric {$metric} metric.",
            );

            $metrics[$metric] = (float) $value;
        }

        return $metrics;
    }

    /**
     * Read a scenario from a snapshot written before the schema was versioned.
     *
     * Those snapshots hold either the metric object or a bare number, which was
     * the median time alone. Memory metrics an old snapshot never carried stay
     * null so the comparison reports them as unavailable instead of comparing
     * against a zero baseline.
     *
     * @return BaselineMetrics
     */
    private static function unversionedMetrics(mixed $result, string $scenario, string $path): array
    {
        if (is_numeric($result)) {
            return [
                'median_ms' => (float) $result,
                'median_peak_mb' => null,
                'median_retained_mb' => null,
            ];
        }

        throw_unless(
            is_array($result),
            RuntimeException::class,
            "Scenario [{$scenario}] in the unversioned benchmark snapshot {$path} is neither a median time nor a set of metrics.",
        );

        throw_unless(
            is_numeric($result['median_ms'] ?? null),
            RuntimeException::class,
            "Scenario [{$scenario}] in the unversioned benchmark snapshot {$path} is missing a numeric median_ms metric.",
        );

        return [
            'median_ms' => (float) $result['median_ms'],
            'median_peak_mb' => is_numeric($result['median_peak_mb'] ?? null) ? (float) $result['median_peak_mb'] : null,
            'median_retained_mb' => is_numeric($result['median_retained_mb'] ?? null) ? (float) $result['median_retained_mb'] : null,
        ];
    }
}

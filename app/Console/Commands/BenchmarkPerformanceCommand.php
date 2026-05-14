<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Benchmark\BenchmarkIsolation;
use App\Console\Benchmark\PerfBenchmarkStatistics;
use App\Console\Benchmark\PerfScenarioRunner;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * @phpstan-type ScenarioMeasurement array{median_ms: float, median_peak_mb: float, median_retained_mb: float}
 * @phpstan-type ScenarioReport array{median_ms: float, samples_ms: list<float>, median_peak_mb: float, samples_peak_mb: list<float>, median_retained_mb: float, samples_retained_mb: list<float>}
 */
class BenchmarkPerformanceCommand extends Command
{
    protected $signature = 'rfa:benchmark-perf
        {--child : Run a single benchmark sample in a child process}
        {--json : Emit JSON instead of a table}
        {--snapshot= : Write aggregated results to this file}
        {--compare= : Compare aggregated results to this snapshot file}
        {--samples=5 : Number of measured child-process samples}
        {--warmup-samples=1 : Number of child-process warmup samples to discard}
        {--rounds=7 : Number of measured rounds per scenario inside each child}
        {--warmup-rounds=2 : Number of warmup rounds per scenario inside each child}
        {--max-regression=5 : Allowed regression percentage before failing}
        {--min-absolute-ms=1 : Minimum absolute increase (ms) before a percentage regression counts}
        {--max-memory-regression=10 : Allowed peak memory regression percentage before failing}
        {--min-absolute-memory-mb=3 : Minimum absolute peak memory increase before a memory regression counts}
        {--max-retained-memory-regression=10 : Allowed retained memory regression percentage before failing}
        {--min-absolute-retained-memory-mb=3 : Minimum absolute retained memory increase before a memory regression counts}';

    protected $description = 'Benchmark representative RFA rendering scenarios';

    public function handle(PerfScenarioRunner $runner, BenchmarkIsolation $benchmarkIsolation): int
    {
        if ((bool) $this->option('child')) {
            return $this->runChildSample($runner, $benchmarkIsolation);
        }

        $report = $this->collectReport($benchmarkIsolation);

        if ($snapshotPath = $this->option('snapshot')) {
            $this->writeSnapshot($snapshotPath, $report);
        }

        if ($comparePath = $this->option('compare')) {
            return $this->compareAgainstSnapshot($comparePath, $report);
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encodeReport($report));

            return self::SUCCESS;
        }

        $this->renderCurrentResults($report);

        return self::SUCCESS;
    }

    private function runChildSample(PerfScenarioRunner $runner, BenchmarkIsolation $benchmarkIsolation): int
    {
        $benchmarkIsolation->activate();

        $report = [
            'generated_at' => now()->toIso8601String(),
            'results' => $runner->measureAll(
                rounds: (int) $this->option('rounds'),
                warmupRounds: (int) $this->option('warmup-rounds'),
            ),
        ];

        $this->line($this->encodeReport($report));

        return self::SUCCESS;
    }

    /**
     * @return array{generated_at: string, config: array<string, int|float>, results: array<string, ScenarioReport>}
     */
    private function collectReport(BenchmarkIsolation $benchmarkIsolation): array
    {
        $warmupSamples = (int) $this->option('warmup-samples');
        $samples = (int) $this->option('samples');
        $measurementsByScenario = [];

        for ($i = 0; $i < $warmupSamples; $i++) {
            $this->runChildProcess($benchmarkIsolation);
        }

        for ($i = 0; $i < $samples; $i++) {
            $sample = $this->runChildProcess($benchmarkIsolation);

            foreach ($sample['results'] as $scenario => $measurement) {
                $measurementsByScenario[$scenario] ??= [
                    'ms' => [],
                    'peak_mb' => [],
                    'retained_mb' => [],
                ];
                $measurementsByScenario[$scenario]['ms'][] = $measurement['median_ms'];
                $measurementsByScenario[$scenario]['peak_mb'][] = $measurement['median_peak_mb'];
                $measurementsByScenario[$scenario]['retained_mb'][] = $measurement['median_retained_mb'];
            }
        }

        $results = [];

        foreach ($measurementsByScenario as $scenario => $measurements) {
            $filteredMilliseconds = PerfBenchmarkStatistics::filterOutliers($measurements['ms']);
            $filteredPeakMegabytes = PerfBenchmarkStatistics::filterOutliers($measurements['peak_mb']);
            $filteredRetainedMegabytes = PerfBenchmarkStatistics::filterOutliers($measurements['retained_mb']);

            $results[$scenario] = [
                'median_ms' => round(PerfBenchmarkStatistics::median($filteredMilliseconds), 3),
                'samples_ms' => array_map(
                    fn (float $value): float => round($value, 3),
                    $measurements['ms'],
                ),
                'median_peak_mb' => round(PerfBenchmarkStatistics::median($filteredPeakMegabytes), 3),
                'samples_peak_mb' => array_map(
                    fn (float $value): float => round($value, 3),
                    $measurements['peak_mb'],
                ),
                'median_retained_mb' => round(PerfBenchmarkStatistics::median($filteredRetainedMegabytes), 3),
                'samples_retained_mb' => array_map(
                    fn (float $value): float => round($value, 3),
                    $measurements['retained_mb'],
                ),
            ];
        }

        ksort($results);

        return [
            'generated_at' => now()->toIso8601String(),
            'config' => [
                'samples' => $samples,
                'warmup_samples' => $warmupSamples,
                'rounds' => (int) $this->option('rounds'),
                'warmup_rounds' => (int) $this->option('warmup-rounds'),
                'max_regression' => (float) $this->option('max-regression'),
                'max_memory_regression' => (float) $this->option('max-memory-regression'),
                'max_retained_memory_regression' => (float) $this->option('max-retained-memory-regression'),
            ],
            'results' => $results,
        ];
    }

    /**
     * @return array{generated_at: string, results: array<string, ScenarioMeasurement>}
     */
    private function runChildProcess(BenchmarkIsolation $benchmarkIsolation): array
    {
        $environment = $benchmarkIsolation->createEnvironment();
        $databasePath = $environment[BenchmarkIsolation::ENV_DATABASE];

        $process = new Process([
            PHP_BINARY,
            'artisan',
            'rfa:benchmark-perf',
            '--child',
            '--json',
            '--rounds='.$this->option('rounds'),
            '--warmup-rounds='.$this->option('warmup-rounds'),
        ], base_path(), $environment);

        try {
            $process->setTimeout(null);
            $process->mustRun();

            $payload = json_decode(trim($process->getOutput()), true);

            if (! is_array($payload) || ! isset($payload['results']) || ! is_array($payload['results'])) {
                throw new \RuntimeException('Unable to decode benchmark child-process output.');
            }

            return [
                'generated_at' => (string) ($payload['generated_at'] ?? now()->toIso8601String()),
                'results' => $this->normalizeChildResults($payload['results']),
            ];
        } finally {
            $benchmarkIsolation->cleanupDatabase($databasePath);
        }
    }

    /**
     * @param  array<string, mixed>  $results
     * @return array<string, ScenarioMeasurement>
     */
    private function normalizeChildResults(array $results): array
    {
        $normalized = [];

        foreach ($results as $scenario => $measurement) {
            if (is_array($measurement)) {
                $normalized[$scenario] = [
                    'median_ms' => (float) ($measurement['median_ms'] ?? 0.0),
                    'median_peak_mb' => (float) ($measurement['median_peak_mb'] ?? 0.0),
                    'median_retained_mb' => (float) ($measurement['median_retained_mb'] ?? 0.0),
                ];

                continue;
            }

            $normalized[$scenario] = [
                'median_ms' => (float) $measurement,
                'median_peak_mb' => 0.0,
                'median_retained_mb' => 0.0,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array{generated_at: string, config: array<string, int|float>, results: array<string, ScenarioReport>}  $report
     */
    private function compareAgainstSnapshot(string $path, array $report): int
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Benchmark snapshot not found: {$path}");
        }

        $snapshot = json_decode((string) file_get_contents($path), true);

        if (! is_array($snapshot) || ! isset($snapshot['results']) || ! is_array($snapshot['results'])) {
            throw new \RuntimeException("Invalid benchmark snapshot: {$path}");
        }

        $rows = [];
        $hasRegression = false;
        $maxRegression = (float) $this->option('max-regression');
        $minAbsoluteMs = (float) $this->option('min-absolute-ms');
        $maxMemoryRegression = (float) $this->option('max-memory-regression');
        $minAbsoluteMemoryMb = (float) $this->option('min-absolute-memory-mb');
        $maxRetainedMemoryRegression = (float) $this->option('max-retained-memory-regression');
        $minAbsoluteRetainedMemoryMb = (float) $this->option('min-absolute-retained-memory-mb');

        foreach ($report['results'] as $scenario => $current) {
            $snapshotResult = $snapshot['results'][$scenario] ?? null;
            $baseline = $this->snapshotMetric($snapshotResult, 'median_ms') ?? 0.0;
            $currentMs = $current['median_ms'];
            $change = round(PerfBenchmarkStatistics::percentageChange($baseline, $currentMs), 2);
            $absoluteIncrease = $currentMs - $baseline;
            $baselinePeakMb = $this->snapshotMetric($snapshotResult, 'median_peak_mb');
            $currentPeakMb = $current['median_peak_mb'];
            $memoryChange = $baselinePeakMb === null
                ? null
                : round(PerfBenchmarkStatistics::percentageChange($baselinePeakMb, $currentPeakMb), 2);
            $memoryIncrease = $baselinePeakMb === null ? 0.0 : $currentPeakMb - $baselinePeakMb;
            $baselineRetainedMb = $this->snapshotMetric($snapshotResult, 'median_retained_mb');
            $currentRetainedMb = $current['median_retained_mb'];
            $retainedChange = $baselineRetainedMb === null
                ? null
                : round(PerfBenchmarkStatistics::percentageChange($baselineRetainedMb, $currentRetainedMb), 2);
            $retainedIncrease = $baselineRetainedMb === null ? 0.0 : $currentRetainedMb - $baselineRetainedMb;

            if ($change > $maxRegression && $absoluteIncrease >= $minAbsoluteMs) {
                $hasRegression = true;
            }

            if ($memoryChange !== null && $memoryChange > $maxMemoryRegression && $memoryIncrease >= $minAbsoluteMemoryMb) {
                $hasRegression = true;
            }

            if ($retainedChange !== null && $retainedChange > $maxRetainedMemoryRegression && $retainedIncrease >= $minAbsoluteRetainedMemoryMb) {
                $hasRegression = true;
            }

            $rows[] = [
                $scenario,
                number_format($baseline, 3).'ms',
                number_format($currentMs, 3).'ms',
                sprintf('%+.2f%%', $change),
                $baselinePeakMb === null ? 'n/a' : number_format($baselinePeakMb, 3).'MB',
                number_format($currentPeakMb, 3).'MB',
                $memoryChange === null ? 'n/a' : sprintf('%+.2f%%', $memoryChange),
                $baselineRetainedMb === null ? 'n/a' : number_format($baselineRetainedMb, 3).'MB',
                number_format($currentRetainedMb, 3).'MB',
                $retainedChange === null ? 'n/a' : sprintf('%+.2f%%', $retainedChange),
            ];
        }

        $this->table(['Scenario', 'Base time', 'Current time', 'Time', 'Base peak', 'Current peak', 'Peak', 'Base retained', 'Current retained', 'Retained'], $rows);

        if ($hasRegression) {
            $this->error("Performance regression exceeded time {$maxRegression}%, peak memory {$maxMemoryRegression}%, or retained memory {$maxRetainedMemoryRegression}%.");

            return self::FAILURE;
        }

        $this->info('Performance benchmark is within the allowed time and memory thresholds.');

        return self::SUCCESS;
    }

    /**
     * @param  array{generated_at: string, config: array<string, int|float>, results: array<string, ScenarioReport>}  $report
     */
    private function renderCurrentResults(array $report): void
    {
        $rows = [];

        foreach ($report['results'] as $scenario => $result) {
            $rows[] = [
                $scenario,
                number_format($result['median_ms'], 3).'ms',
                number_format($result['median_peak_mb'], 3).'MB',
                number_format($result['median_retained_mb'], 3).'MB',
                implode(', ', array_map(
                    fn (float $value): string => number_format($value, 3).'ms',
                    $result['samples_ms'],
                )),
            ];
        }

        $this->table(['Scenario', 'Median', 'Peak', 'Retained', 'Samples'], $rows);
    }

    /**
     * @param  array{generated_at: string, config: array<string, int|float>, results: array<string, ScenarioReport>}  $report
     */
    private function writeSnapshot(string $path, array $report): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $this->encodeReport($report));

        $this->info("Benchmark snapshot written to {$path}");
    }

    private function snapshotMetric(mixed $result, string $metric): ?float
    {
        if (is_array($result) && isset($result[$metric]) && is_numeric($result[$metric])) {
            return (float) $result[$metric];
        }

        if ($metric === 'median_ms' && is_numeric($result)) {
            return (float) $result;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function encodeReport(array $report): string
    {
        return (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Benchmark\BenchmarkIsolation;
use App\Console\Benchmark\BenchmarkOptions;
use App\Console\Benchmark\PerfBenchmarkReport;
use App\Console\Benchmark\PerfBenchmarkStatistics;
use App\Console\Benchmark\PerfScenarioRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * @phpstan-import-type BaselineMetrics from PerfBenchmarkReport
 *
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
        {--only=* : Limit the run to the given scenario name}
        {--max-regression=5 : Allowed regression percentage before failing}
        {--min-absolute-ms=1 : Minimum absolute increase (ms) before a percentage regression counts}
        {--max-memory-regression=10 : Allowed peak memory regression percentage before failing}
        {--min-absolute-memory-mb=3 : Minimum absolute peak memory increase before a memory regression counts}
        {--max-retained-memory-regression=10 : Allowed retained memory regression percentage before failing}
        {--min-absolute-retained-memory-mb=3 : Minimum absolute retained memory increase before a memory regression counts}';

    protected $description = 'Benchmark representative RFA rendering scenarios';

    public function handle(PerfScenarioRunner $runner, BenchmarkIsolation $benchmarkIsolation): int
    {
        try {
            $options = BenchmarkOptions::fromOptions($this->options(), $runner->scenarioNames());
            // Read the baseline before measuring: an unreadable snapshot is
            // worth a message now rather than after a full benchmark run.
            $baseline = $options->comparePath === null
                ? null
                : PerfBenchmarkReport::decodeSnapshot($options->comparePath);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($options->child) {
            return $this->runChildSample($options, $runner, $benchmarkIsolation);
        }

        $report = $this->collectReport($options, $benchmarkIsolation);

        if ($options->snapshotPath !== null) {
            $this->writeSnapshot($options->snapshotPath, $report);
        }

        if ($baseline !== null) {
            return $this->compareAgainstSnapshot($options, $baseline['results'], $report);
        }

        if ($options->json) {
            $this->line(PerfBenchmarkReport::encode($report));

            return self::SUCCESS;
        }

        $this->renderCurrentResults($report);

        return self::SUCCESS;
    }

    private function runChildSample(BenchmarkOptions $options, PerfScenarioRunner $runner, BenchmarkIsolation $benchmarkIsolation): int
    {
        $benchmarkIsolation->activate();

        $report = [
            'generated_at' => now()->toIso8601String(),
            'results' => $runner->measureAll(
                rounds: $options->rounds,
                warmupRounds: $options->warmupRounds,
                only: $options->only,
            ),
        ];

        $this->line(PerfBenchmarkReport::encode($report));

        return self::SUCCESS;
    }

    /**
     * @return array{generated_at: string, config: array<string, int|float>, results: array<string, ScenarioReport>}
     */
    private function collectReport(BenchmarkOptions $options, BenchmarkIsolation $benchmarkIsolation): array
    {
        $measurementsByScenario = [];

        for ($i = 0; $i < $options->warmupSamples; $i++) {
            $this->runChildProcess($options, $benchmarkIsolation);
        }

        for ($i = 0; $i < $options->samples; $i++) {
            $sample = $this->runChildProcess($options, $benchmarkIsolation);

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
            'config' => $options->reportConfig(),
            'results' => $results,
        ];
    }

    /**
     * @return array{generated_at: string, results: array<string, ScenarioMeasurement>}
     */
    private function runChildProcess(BenchmarkOptions $options, BenchmarkIsolation $benchmarkIsolation): array
    {
        $environment = $benchmarkIsolation->createEnvironment();
        $databasePath = $environment[BenchmarkIsolation::ENV_DATABASE];

        $process = new Process([
            PHP_BINARY,
            'artisan',
            'rfa:benchmark-perf',
            ...$options->childArguments(),
        ], base_path(), $environment);

        try {
            $process->setTimeout(null);
            $process->mustRun();

            return PerfBenchmarkReport::decodeChildSample($process->getOutput());
        } finally {
            $benchmarkIsolation->cleanupDatabase($databasePath);
        }
    }

    /**
     * @param  array<string, BaselineMetrics>  $baselines
     * @param  array{generated_at: string, config: array<string, int|float>, results: array<string, ScenarioReport>}  $report
     */
    private function compareAgainstSnapshot(BenchmarkOptions $options, array $baselines, array $report): int
    {
        $rows = [];
        $hasRegression = false;

        foreach ($report['results'] as $scenario => $current) {
            $baseline = $baselines[$scenario] ?? null;

            $time = $this->compareMetric(
                $baseline['median_ms'] ?? null,
                $current['median_ms'],
                $options->maxRegression,
                $options->minAbsoluteMs,
            );
            $peak = $this->compareMetric(
                $baseline['median_peak_mb'] ?? null,
                $current['median_peak_mb'],
                $options->maxMemoryRegression,
                $options->minAbsoluteMemoryMb,
            );
            $retained = $this->compareMetric(
                $baseline['median_retained_mb'] ?? null,
                $current['median_retained_mb'],
                $options->maxRetainedMemoryRegression,
                $options->minAbsoluteRetainedMemoryMb,
            );

            $hasRegression = $hasRegression || $time['regressed'] || $peak['regressed'] || $retained['regressed'];

            $rows[] = [
                $scenario,
                $this->formatMetric($time['baseline'], 'ms'),
                $this->formatMetric($current['median_ms'], 'ms'),
                $this->formatChange($time['change']),
                $this->formatMetric($peak['baseline'], 'MB'),
                $this->formatMetric($current['median_peak_mb'], 'MB'),
                $this->formatChange($peak['change']),
                $this->formatMetric($retained['baseline'], 'MB'),
                $this->formatMetric($current['median_retained_mb'], 'MB'),
                $this->formatChange($retained['change']),
            ];
        }

        $this->table(['Scenario', 'Base time', 'Current time', 'Time', 'Base peak', 'Current peak', 'Peak', 'Base retained', 'Current retained', 'Retained'], $rows);

        if ($hasRegression) {
            $this->error("Performance regression exceeded time {$options->maxRegression}%, peak memory {$options->maxMemoryRegression}%, or retained memory {$options->maxRetainedMemoryRegression}%.");

            return self::FAILURE;
        }

        $this->info('Performance benchmark is within the allowed time and memory thresholds.');

        return self::SUCCESS;
    }

    /**
     * A metric the snapshot never recorded has no baseline to regress from, so
     * it reports as unavailable rather than as a change against zero.
     *
     * @return array{baseline: float|null, change: float|null, regressed: bool}
     */
    private function compareMetric(?float $baseline, float $current, float $maxRegression, float $minimumAbsoluteIncrease): array
    {
        if ($baseline === null) {
            return ['baseline' => null, 'change' => null, 'regressed' => false];
        }

        $change = round(PerfBenchmarkStatistics::percentageChange($baseline, $current), 2);

        return [
            'baseline' => $baseline,
            'change' => $change,
            'regressed' => $change > $maxRegression && ($current - $baseline) >= $minimumAbsoluteIncrease,
        ];
    }

    private function formatMetric(?float $value, string $unit): string
    {
        return $value === null ? 'n/a' : number_format($value, 3).$unit;
    }

    private function formatChange(?float $change): string
    {
        return $change === null ? 'n/a' : sprintf('%+.2f%%', $change);
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

        file_put_contents($path, PerfBenchmarkReport::encode($report));

        $this->info("Benchmark snapshot written to {$path}");
    }
}

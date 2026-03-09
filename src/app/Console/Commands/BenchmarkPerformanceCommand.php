<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Benchmark\PerfBenchmarkStatistics;
use App\Console\Benchmark\PerfScenarioRunner;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

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
        {--max-regression=5 : Allowed regression percentage before failing}';

    protected $description = 'Benchmark representative RFA rendering scenarios';

    public function handle(PerfScenarioRunner $runner): int
    {
        if ((bool) $this->option('child')) {
            return $this->runChildSample($runner);
        }

        $report = $this->collectReport();

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

    private function runChildSample(PerfScenarioRunner $runner): int
    {
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
     * @return array{generated_at: string, config: array<string, int|float>, results: array<string, array{median_ms: float, samples_ms: list<float>}>}
     */
    private function collectReport(): array
    {
        $warmupSamples = (int) $this->option('warmup-samples');
        $samples = (int) $this->option('samples');
        $measurementsByScenario = [];

        for ($i = 0; $i < $warmupSamples; $i++) {
            $this->runChildProcess();
        }

        for ($i = 0; $i < $samples; $i++) {
            $sample = $this->runChildProcess();

            foreach ($sample['results'] as $scenario => $ms) {
                $measurementsByScenario[$scenario] ??= [];
                $measurementsByScenario[$scenario][] = $ms;
            }
        }

        $results = [];

        foreach ($measurementsByScenario as $scenario => $measurements) {
            $filtered = PerfBenchmarkStatistics::filterOutliers($measurements);

            $results[$scenario] = [
                'median_ms' => round(PerfBenchmarkStatistics::median($filtered), 3),
                'samples_ms' => array_map(
                    fn (float $value): float => round($value, 3),
                    $measurements,
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
            ],
            'results' => $results,
        ];
    }

    /**
     * @return array{generated_at: string, results: array<string, float>}
     */
    private function runChildProcess(): array
    {
        $process = new Process([
            PHP_BINARY,
            'artisan',
            'rfa:benchmark-perf',
            '--child',
            '--json',
            '--rounds='.$this->option('rounds'),
            '--warmup-rounds='.$this->option('warmup-rounds'),
        ], base_path());

        $process->setTimeout(null);
        $process->mustRun();

        $payload = json_decode(trim($process->getOutput()), true);

        if (! is_array($payload) || ! isset($payload['results']) || ! is_array($payload['results'])) {
            throw new \RuntimeException('Unable to decode benchmark child-process output.');
        }

        return [
            'generated_at' => (string) ($payload['generated_at'] ?? now()->toIso8601String()),
            'results' => array_map(
                fn (mixed $value): float => (float) $value,
                $payload['results'],
            ),
        ];
    }

    /**
     * @param  array{generated_at: string, config: array<string, int|float>, results: array<string, array{median_ms: float, samples_ms: list<float>}>}  $report
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

        foreach ($report['results'] as $scenario => $current) {
            $baseline = (float) ($snapshot['results'][$scenario]['median_ms'] ?? 0.0);
            $currentMs = $current['median_ms'];
            $change = round(PerfBenchmarkStatistics::percentageChange($baseline, $currentMs), 2);

            if ($change > $maxRegression) {
                $hasRegression = true;
            }

            $rows[] = [
                $scenario,
                number_format($baseline, 3).'ms',
                number_format($currentMs, 3).'ms',
                sprintf('%+.2f%%', $change),
            ];
        }

        $this->table(['Scenario', 'Baseline', 'Current', 'Change'], $rows);

        if ($hasRegression) {
            $this->error("Performance regression exceeded {$maxRegression}%.");

            return self::FAILURE;
        }

        $this->info('Performance benchmark is within the allowed regression threshold.');

        return self::SUCCESS;
    }

    /**
     * @param  array{generated_at: string, config: array<string, int|float>, results: array<string, array{median_ms: float, samples_ms: list<float>}>}  $report
     */
    private function renderCurrentResults(array $report): void
    {
        $rows = [];

        foreach ($report['results'] as $scenario => $result) {
            $rows[] = [
                $scenario,
                number_format($result['median_ms'], 3).'ms',
                implode(', ', array_map(
                    fn (float $value): string => number_format($value, 3).'ms',
                    $result['samples_ms'],
                )),
            ];
        }

        $this->table(['Scenario', 'Median', 'Samples'], $rows);
    }

    /**
     * @param  array{generated_at: string, config: array<string, int|float>, results: array<string, array{median_ms: float, samples_ms: list<float>}>}  $report
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

    /**
     * @param  array<string, mixed>  $report
     */
    private function encodeReport(array $report): string
    {
        return (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

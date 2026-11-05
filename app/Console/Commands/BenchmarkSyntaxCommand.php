<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BenchmarkSyntaxAction;
use App\Console\Benchmark\PerfBenchmarkStatistics;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Phiki\TextMate\Engines\PcreEngine;
use Symfony\Component\Process\Process;

class BenchmarkSyntaxCommand extends Command
{
    protected $signature = 'rfa:benchmark-syntax
        {files* : Source files to highlight as full-context diffs}
        {--backend=* : auto, phiki, or phiki-pcre (default: auto and phiki)}
        {--samples=5 : Fresh PHP processes per file and backend}
        {--rounds=3 : Measured rounds per process}
        {--warmup-rounds=1 : Discarded rounds per process}
        {--json : Emit JSON with all samples and input hashes}
        {--child : Internal single-process measurement}';

    protected $description = 'Compare syntax highlighters through the RFA diff adapter';

    public function handle(BenchmarkSyntaxAction $benchmarkSyntax): int
    {
        foreach (['samples', 'rounds', 'warmup-rounds'] as $option) {
            $value = filter_var($this->option($option), FILTER_VALIDATE_INT);
            if ($value === false || $value < ($option === 'warmup-rounds' ? 0 : 1)) {
                $this->error("The {$option} option must be an integer of at least ".($option === 'warmup-rounds' ? 0 : 1).'.');

                return self::INVALID;
            }
        }

        $backends = $this->option('backend') ?: ['auto', 'phiki'];
        foreach ($backends as $backend) {
            if (! in_array($backend, ['auto', 'phiki', 'phiki-pcre'], true)) {
                $this->error("Unknown backend: {$backend}. Use auto, phiki, or phiki-pcre.");

                return self::INVALID;
            }
            if ($backend === 'phiki-pcre' && ! class_exists(PcreEngine::class)) {
                $this->error('The phiki-pcre backend requires Phiki 3.');

                return self::INVALID;
            }
        }

        $results = [];
        foreach ($this->argument('files') as $file) {
            $absolutePath = str_starts_with($file, '/') ? $file : base_path($file);
            $disk = Storage::build(['driver' => 'local', 'root' => dirname($absolutePath), 'throw' => true]);
            if (! $disk->exists(basename($absolutePath))) {
                $this->error("Source file does not exist: {$file}");

                return self::INVALID;
            }
            $code = $disk->get(basename($absolutePath));

            foreach ($backends as $backend) {
                $samples = [];
                for ($sample = 0; $sample < ($this->option('child') ? 1 : (int) $this->option('samples')); $sample++) {
                    if ($this->option('child')) {
                        $samples[] = $benchmarkSyntax->handle($code, $file, $backend, (int) $this->option('rounds'), (int) $this->option('warmup-rounds'));

                        continue;
                    }
                    $process = new Process([
                        PHP_BINARY, 'artisan', 'rfa:benchmark-syntax', $file,
                        '--child', '--json', '--backend='.$backend,
                        '--rounds='.$this->option('rounds'), '--warmup-rounds='.$this->option('warmup-rounds'),
                    ], base_path());
                    $process->setTimeout(null)->mustRun();
                    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                    $samples[] = $report['results'][0]['samples'][0];
                }
                $results[] = [
                    'file' => $file,
                    'sha256' => hash('sha256', $code),
                    'lines' => substr_count($code, "\n") + 1,
                    'backend' => $backend,
                    'actual_highlighter' => $samples[0]['actual_highlighter'],
                    'median_ms' => round(PerfBenchmarkStatistics::median(array_column($samples, 'median_ms')), 3),
                    'median_peak_mb' => round(PerfBenchmarkStatistics::median(array_column($samples, 'median_peak_mb')), 3),
                    'samples' => $samples,
                ];
            }
        }

        $report = [
            'php' => PHP_VERSION,
            'platform' => php_uname(),
            'phiki' => InstalledVersions::getPrettyVersion('phiki/phiki'),
            'tempest' => InstalledVersions::getPrettyVersion('tempest/highlight'),
            'rounds' => (int) $this->option('rounds'),
            'warmup_rounds' => (int) $this->option('warmup-rounds'),
            'results' => $results,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("PHP {$report['php']}, Phiki {$report['phiki']}, Tempest {$report['tempest']}");
        $this->table(['File', 'Lines', 'Backend', 'Actual', 'Median ms', 'Peak MB'], collect($results)->map(fn (array $row): array => [
            $row['file'], $row['lines'], $row['backend'], $row['actual_highlighter'], $row['median_ms'], $row['median_peak_mb'],
        ])->all());

        return self::SUCCESS;
    }
}

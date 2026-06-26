<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Providers\NativeAppServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Measures RFA's PHP-side cold-start launch sequence — the part the startup
 * patches control — so the win can be verified reproducibly on any machine
 * (this is what to run on macOS to confirm the real bundled-PHP numbers).
 *
 * It times the two phases NativePHP runs before the window appears:
 *   - framework boot ×2  (proxy for `native:php-ini` + `native:config`)
 *   - the cache step      (`optimize` stock vs `config:cache` patched)
 * for both the stock path (no opcache, full optimize every launch) and the
 * patched path (opcache file cache warm, optimize only on version change), and
 * reports the per-launch totals plus the delta.
 *
 * The Electron native boot (window create) is not included — it is fixed native
 * cost untouched by app code; the renderer's `boot` diagnostic sample
 * (`navigation.domCompleteMs`) captures the first-paint side on a real launch.
 */
class StartupBenchCommand extends Command
{
    protected $signature = 'rfa:startup-bench
        {--noop : Boot the framework and exit immediately (used as the boot-cost probe)}
        {--samples=5 : Measured samples per step}
        {--warmup=2 : Warmup samples discarded per step}
        {--json : Emit JSON instead of a table}';

    protected $description = 'Benchmark the PHP-side cold-start launch sequence (stock vs patched)';

    public function handle(): int
    {
        // The --noop child exists only to be timed: by the time Laravel invokes
        // this handler the framework has fully booted, which is the cost we want.
        if ((bool) $this->option('noop')) {
            return self::SUCCESS;
        }

        $samples = max(1, (int) $this->option('samples'));
        $warmup = max(0, (int) $this->option('warmup'));

        $cacheDir = sys_get_temp_dir().'/rfa_startup_bench_'.getmypid().'_'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($cacheDir);

        $opcacheAvailable = $this->opcacheAvailable();

        try {
            $opcache = $this->opcacheFlags($cacheDir);

            // Warm the opcache file cache so the "patched" boots reuse opcode,
            // mirroring a same-version launch after the cache has been populated.
            $this->time(['rfa:startup-bench', '--noop'], $opcache, 2);

            $stockBoot = $this->median(['rfa:startup-bench', '--noop'], [], $samples, $warmup);
            $patchedBoot = $this->median(['rfa:startup-bench', '--noop'], $opcache, $samples, $warmup);
            $stockCache = $this->median(['optimize'], [], $samples, $warmup);
            // Stock NativePHP runs `optimize` on every launch; the patched warm
            // launch skips the cache step entirely (the version-cached config
            // persists and the per-launch port/secret are re-read from the live
            // environment at runtime). Still measure what a `config:cache` boot —
            // the step the previous patch revision paid every launch — would cost,
            // so the saving is visible, but the warm-launch cache step is 0.
            $skippedConfigCache = $this->median(['config:cache'], $opcache, $samples, $warmup);
            $patchedCache = 0.0;
        } finally {
            File::deleteDirectory($cacheDir);
            // Restore the un-optimized dev state the bench started from.
            $this->time(['optimize:clear'], [], 1);
        }

        $report = $this->summarize($stockBoot, $patchedBoot, $stockCache, $patchedCache, $opcacheAvailable);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                [...$report, 'skipped_config_cache_ms' => round($skippedConfigCache, 1)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        $this->info(sprintf(
            'Warm same-version launches now skip the cache step entirely (the previous patch '
            .'revision still paid a ~%sms config:cache boot every launch).',
            number_format(round($skippedConfigCache, 1), 1),
        ));

        return self::SUCCESS;
    }

    /**
     * Build the report rows and totals. Pure so it can be unit-tested.
     *
     * @return array{
     *     opcache_available: bool,
     *     rows: list<array{phase: string, stock_ms: float, patched_ms: float}>,
     *     stock_total_ms: float,
     *     patched_total_ms: float,
     *     saved_ms: float
     * }
     */
    public function summarize(
        float $stockBoot,
        float $patchedBoot,
        float $stockCache,
        float $patchedCache,
        bool $opcacheAvailable,
    ): array {
        $rows = [
            ['phase' => 'framework boot ×2 (native:php-ini + native:config)', 'stock_ms' => round($stockBoot * 2, 1), 'patched_ms' => round($patchedBoot * 2, 1)],
            ['phase' => 'cache step (optimize → skipped on warm launch)', 'stock_ms' => round($stockCache, 1), 'patched_ms' => round($patchedCache, 1)],
        ];

        $stockTotal = round($stockBoot * 2 + $stockCache, 1);
        $patchedTotal = round($patchedBoot * 2 + $patchedCache, 1);

        return [
            'opcache_available' => $opcacheAvailable,
            'rows' => $rows,
            'stock_total_ms' => $stockTotal,
            'patched_total_ms' => $patchedTotal,
            'saved_ms' => round($stockTotal - $patchedTotal, 1),
        ];
    }

    /**
     * @param  array{opcache_available: bool, rows: list<array{phase: string, stock_ms: float, patched_ms: float}>, stock_total_ms: float, patched_total_ms: float, saved_ms: float}  $report
     */
    private function renderReport(array $report): void
    {
        if (! $report['opcache_available']) {
            $this->warn('opcache is NOT active in this PHP — the opcache wins will not show. '
                .'On macOS confirm the bundled NativePHP php binary has opcache compiled in.');
        }

        $this->table(
            ['Phase', 'Stock', 'Patched'],
            collect($report['rows'])
                ->map(fn (array $row): array => [
                    $row['phase'],
                    number_format($row['stock_ms'], 1).'ms',
                    number_format($row['patched_ms'], 1).'ms',
                ])
                ->push([
                    '<info>PHP-side launch total</info>',
                    '<info>'.number_format($report['stock_total_ms'], 1).'ms</info>',
                    '<info>'.number_format($report['patched_total_ms'], 1).'ms</info>',
                ])
                ->all(),
        );

        $this->info(sprintf(
            'PHP-side cold start: %sms → %sms (−%sms). Electron native boot + first paint are measured separately via the renderer "boot" diagnostic.',
            number_format($report['stock_total_ms'], 1),
            number_format($report['patched_total_ms'], 1),
            number_format($report['saved_ms'], 1),
        ));
    }

    /**
     * @param  list<string>  $artisanArgs
     * @param  list<string>  $opcacheFlags
     */
    private function median(array $artisanArgs, array $opcacheFlags, int $samples, int $warmup): float
    {
        for ($i = 0; $i < $warmup; $i++) {
            $this->time($artisanArgs, $opcacheFlags, 1);
        }

        $measurements = [];

        for ($i = 0; $i < $samples; $i++) {
            $measurements[] = $this->time($artisanArgs, $opcacheFlags, 1);
        }

        sort($measurements);
        $middle = intdiv(count($measurements), 2);

        return count($measurements) % 2 === 0
            ? ($measurements[$middle - 1] + $measurements[$middle]) / 2
            : $measurements[$middle];
    }

    /**
     * Spawn a fresh `php [-d opcache...] artisan <args>` child `$repeat` times and
     * return the average wall-clock in milliseconds — a faithful proxy for one
     * launch-time boot.
     *
     * @param  list<string>  $artisanArgs
     * @param  list<string>  $opcacheFlags
     */
    private function time(array $artisanArgs, array $opcacheFlags, int $repeat): float
    {
        $command = [PHP_BINARY, ...$opcacheFlags, 'artisan', ...$artisanArgs];
        $repeat = max(1, $repeat);

        $started = hrtime(true);

        for ($i = 0; $i < $repeat; $i++) {
            $process = new Process($command, base_path());
            $process->setTimeout(120);
            $process->run();
        }

        return ((hrtime(true) - $started) / 1_000_000) / $repeat;
    }

    /**
     * The production opcache directives, mirroring
     * {@see NativeAppServiceProvider::phpIni()} and the
     * vendored-server patch, as `-d key=value` CLI flags.
     *
     * @return list<string>
     */
    private function opcacheFlags(string $cacheDir): array
    {
        return [
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.validate_timestamps=1',
            '-d', 'opcache.revalidate_freq=0',
            '-d', 'opcache.memory_consumption=192',
            '-d', 'opcache.max_accelerated_files=30000',
            '-d', 'opcache.file_cache='.$cacheDir,
        ];
    }

    private function opcacheAvailable(): bool
    {
        $process = new Process([
            PHP_BINARY,
            '-d', 'opcache.enable_cli=1',
            '-r', 'echo (function_exists("opcache_get_status") && @opcache_get_status(false) !== false) ? "1" : "0";',
        ]);
        $process->run();

        return trim($process->getOutput()) === '1';
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BuildLaunchReportAction;
use Illuminate\Console\Command;

/**
 * Prints the cold-launch timeline of the installed app: every mark the main
 * process, the PHP server, and the renderer recorded, in milliseconds since
 * the process was created, across the most recent launches with a median.
 *
 * @phpstan-import-type Launch from BuildLaunchReportAction
 */
class LaunchReportCommand extends Command
{
    protected $signature = 'rfa:launch-report
        {--logs= : Log directory holding rfa-launch.jsonl and rfa-diagnostics.jsonl (defaults to the installed app)}
        {--launches=5 : How many of the most recent launches to include}
        {--json : Emit JSON instead of tables}';

    protected $description = 'Report the cold-launch timeline recorded by the installed app';

    public function handle(BuildLaunchReportAction $report): int
    {
        $logsDirectory = $this->logsDirectory();
        ['launches' => $launches, 'medians' => $medians, 'phases' => $phases] = $report->handle($logsDirectory, (int) $this->option('launches'));

        if ($launches === []) {
            $this->error("No launch timeline found in {$logsDirectory}. Launch the packaged app once, then run this again.");

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'logs' => $logsDirectory,
                'launches' => $launches,
                'medians' => $medians,
                'phases' => $phases,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('%d launch(es) from %s', count($launches), $logsDirectory));
        $this->newLine();

        $this->table(
            ['mark', ...array_map(fn (array $launch): string => $this->launchHeading($launch), $launches), 'median', 'step'],
            $this->markRows($launches, $medians),
        );

        $this->newLine();
        $this->table(['phase', 'median ms'], collect($phases)->map(fn (int $duration, string $phase): array => [$phase, $duration])->values()->all());

        return self::SUCCESS;
    }

    /**
     * @param  list<Launch>  $launches
     * @param  array<string, int>  $medians
     * @return list<list<string|int>>
     */
    private function markRows(array $launches, array $medians): array
    {
        $previous = 0;
        $rows = [];

        foreach ($medians as $mark => $median) {
            $rows[] = [
                $mark,
                ...array_map(fn (array $launch): string => isset($launch['marks'][$mark]) ? (string) $launch['marks'][$mark] : '-', $launches),
                $median,
                '+'.($median - $previous),
            ];
            $previous = $median;
        }

        return $rows;
    }

    /** @param Launch $launch */
    private function launchHeading(array $launch): string
    {
        $time = substr($launch['ts'], 11, 8);

        return $time === '' ? 'launch' : $time;
    }

    /**
     * The installed app's log directory when this runs from the dev checkout,
     * otherwise the running app's own storage.
     */
    private function logsDirectory(): string
    {
        $option = $this->option('logs');

        if (is_string($option) && $option !== '') {
            return rtrim($option, '/');
        }

        if ((bool) config('nativephp-internal.running')) {
            return storage_path('logs');
        }

        $installed = ($_SERVER['HOME'] ?? '').'/Library/Application Support/'.config('app.name').'/storage/logs';

        return is_dir($installed) ? $installed : storage_path('logs');
    }
}

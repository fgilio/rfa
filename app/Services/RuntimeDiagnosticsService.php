<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Process\Process;
use Throwable;

final class RuntimeDiagnosticsService
{
    public function __construct(
        private readonly BrowserDiagnosticSampleFormatter $formatter,
    ) {}

    /**
     * Record a timestamped diagnostic breadcrumb.
     *
     * The file is intentionally JSONL so partial corruption only affects
     * one entry and shell tooling can stream it without loading the file.
     *
     * @param  array<string, mixed>  $context
     */
    public function breadcrumb(string $event, array $context = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->append([
            'ts' => now()->toISOString(),
            'event' => $event,
            'pid' => getmypid() ?: null,
            'php' => $this->phpMemory(),
            'context' => $this->normalize($context),
        ]);
    }

    /**
     * Record a browser-originated heartbeat or workflow event.
     *
     * @param  array<string, mixed>  $sample  validated by BrowserDiagnosticSampleRequest
     */
    public function recordBrowserSample(array $sample): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->breadcrumb('browser.sample', $this->formatter->format($sample));

        if (($sample['includeProcessSnapshot'] ?? false) === true) {
            $this->breadcrumb('system.processes', [
                'processes' => $this->rfaProcesses(),
            ]);
        }
    }

    public function enabled(): bool
    {
        return (bool) config('rfa.diagnostics.enabled', true);
    }

    /** @return array{memory_mb: float, peak_mb: float} */
    private function phpMemory(): array
    {
        return [
            'memory_mb' => $this->bytesToMegabytes(memory_get_usage(true)),
            'peak_mb' => $this->bytesToMegabytes(memory_get_peak_usage(true)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rfaProcesses(): array
    {
        if (! (bool) config('rfa.diagnostics.process_snapshots', PHP_OS_FAMILY === 'Darwin')) {
            return [];
        }

        // Force the C locale so `ps` prints %cpu/%mem with a period decimal.
        // Under a comma-decimal locale (e.g. LC_NUMERIC=de_DE) it emits "12,5",
        // which the numeric capture groups in parseProcessLine cannot match,
        // dropping every process from the snapshot.
        $processList = new Process(
            ['ps', '-axo', 'pid=,ppid=,%cpu=,%mem=,rss=,stat=,etime=,comm=,command='],
            env: ['LC_ALL' => 'C'],
        );
        $processList->setTimeout((float) config('rfa.diagnostics.process_snapshot_timeout_seconds', 2));

        try {
            $processList->run();
        } catch (Throwable) {
            return [];
        }

        if (! $processList->isSuccessful()) {
            return [];
        }

        $lines = explode("\n", $processList->getOutput());

        $processes = [];

        foreach ($lines as $line) {
            $process = $this->parseProcessLine($line);

            if ($process === null || ! $this->isRfaProcess($process)) {
                continue;
            }

            $processes[] = [
                'pid' => $process['pid'],
                'ppid' => $process['ppid'],
                'role' => $this->processRole($process['command']),
                'name' => basename($process['comm']),
                'cpu_percent' => $process['cpu_percent'],
                'memory_percent' => $process['memory_percent'],
                'rss_mb' => $this->bytesToMegabytes($process['rss_kb'] * 1024),
                'state' => $this->shortString($process['state'], 16),
                'elapsed' => $this->shortString($process['elapsed'], 32),
                'command_hash' => hash('xxh128', $process['command']),
                'command_features' => $this->processCommandFeatures($process['command']),
            ];
        }

        return collect($processes)
            ->sortByDesc('rss_mb')
            ->values()
            ->all();
    }

    /**
     * @return array{pid: int, ppid: int, cpu_percent: float, memory_percent: float, rss_kb: int, state: string, elapsed: string, comm: string, command: string}|null
     */
    private function parseProcessLine(string $line): ?array
    {
        if (! preg_match('/^\s*(\d+)\s+(\d+)\s+([\d.]+)\s+([\d.]+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.*)$/', $line, $matches)) {
            return null;
        }

        return [
            'pid' => (int) $matches[1],
            'ppid' => (int) $matches[2],
            'cpu_percent' => (float) $matches[3],
            'memory_percent' => (float) $matches[4],
            'rss_kb' => (int) $matches[5],
            'state' => $matches[6],
            'elapsed' => $matches[7],
            'comm' => $matches[8],
            'command' => $matches[9],
        ];
    }

    /** @param array{comm: string, command: string} $process */
    private function isRfaProcess(array $process): bool
    {
        $haystack = strtolower($process['comm'].' '.$process['command']);

        return str_contains($haystack, '/rfa.app/')
            || str_contains($haystack, 'rfa helper')
            || str_contains($haystack, 'rfa-dev')
            || preg_match('/(^|\/)rfa($|\s)/', $haystack) === 1;
    }

    private function processRole(string $command): string
    {
        $lower = strtolower($command);

        // Electron 38 exposes these helper roles in the process command line.
        return match (true) {
            str_contains($lower, 'helper (renderer)') => 'renderer',
            str_contains($lower, '--type=renderer') => 'renderer',
            str_contains($lower, 'helper (gpu)') => 'gpu',
            str_contains($lower, '--type=gpu-process') => 'gpu',
            str_contains($lower, 'helper') => 'helper',
            str_contains($lower, '--type=utility') => 'helper',
            default => 'main',
        };
    }

    /** @return array<string, mixed>|null */
    private function processCommandFeatures(string $command): ?array
    {
        if (! (bool) config('rfa.diagnostics.process_snapshot_command_features', true)) {
            return null;
        }

        $features = array_filter([
            'type' => $this->processSwitch($command, 'type'),
            'utility' => $this->processSwitch($command, 'utility-sub-type'),
            'enabled' => $this->processFeatureList($command, 'enable-features'),
            'disabled' => $this->processFeatureList($command, 'disable-features'),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return $features === [] ? null : $features;
    }

    private function processSwitch(string $command, string $name): ?string
    {
        if (! preg_match('/--'.preg_quote($name, '/').'=([^\s]+)/', $command, $matches)) {
            return null;
        }

        return $this->shortString($matches[1], 128);
    }

    /** @return list<string> */
    private function processFeatureList(string $command, string $name): array
    {
        $switch = $this->processSwitch($command, $name);

        if ($switch === null) {
            return [];
        }

        return collect(explode(',', $switch))
            ->map(fn (string $feature): string => trim($feature))
            ->filter()
            ->take(40)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $entry */
    private function append(array $entry): void
    {
        $path = (string) config('rfa.diagnostics.path', storage_path('logs/rfa-diagnostics.jsonl'));
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return;
        }

        try {
            $this->rotateIfNeeded($path);
            $line = json_encode(
                $entry,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (Throwable) {
            return;
        }

        $handle = @fopen($path, 'ab');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, LOCK_UN);
        } catch (Throwable) {
            // Diagnostics must never affect the application workflow.
        } finally {
            fclose($handle);
        }
    }

    private function rotateIfNeeded(string $path): void
    {
        // RFA writes diagnostics from two processes (main: deep-link/menu/boot
        // breadcrumbs; renderer: browser samples). Serialize rotation behind a
        // dedicated lock file so two processes hitting the size threshold at once
        // can't both shuffle the .1.. .N files and clobber a rotated segment.
        $lockHandle = @fopen($path.'.lock', 'c');
        if ($lockHandle === false) {
            return;
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                return;
            }

            clearstatcache(true, $path);

            // Re-check under the lock: if another process already rotated, $path is
            // the fresh (small) file and there's nothing to do.
            if (! is_file($path) || filesize($path) < (int) config('rfa.diagnostics.max_file_bytes', 5 * 1024 * 1024)) {
                return;
            }

            $maxFiles = max(1, (int) config('rfa.diagnostics.max_files', 5));
            $oldest = "{$path}.{$maxFiles}";

            if (is_file($oldest)) {
                @unlink($oldest);
            }

            for ($index = $maxFiles - 1; $index >= 1; $index--) {
                $source = "{$path}.{$index}";

                if (is_file($source)) {
                    @rename($source, "{$path}.".($index + 1));
                }
            }

            @rename($path, "{$path}.1");
        } catch (Throwable) {
            return;
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    private function bytesToMegabytes(int|float $bytes): float
    {
        return round($bytes / 1024 / 1024, 3);
    }

    private function shortString(mixed $value, int $limit): string
    {
        $string = is_scalar($value) ? (string) $value : get_debug_type($value);

        if (strlen($string) <= $limit) {
            return $string;
        }

        return substr($string, 0, $limit - 3).'...';
    }

    private function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '[depth-limit]';
        }

        if (is_string($value)) {
            return $this->shortString($value, 500);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (! is_array($value)) {
            return get_debug_type($value);
        }

        $normalized = [];

        foreach (array_slice($value, 0, 50, preserve_keys: true) as $key => $item) {
            $normalized[is_int($key) ? $key : $this->shortString($key, 80)] = $this->normalize($item, $depth + 1);
        }

        if (count($value) > 50) {
            $normalized['_truncated'] = count($value) - 50;
        }

        return $normalized;
    }
}

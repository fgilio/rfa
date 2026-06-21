<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

final class RuntimeDiagnosticsService
{
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
     * @param  array<string, mixed>  $payload
     */
    public function recordBrowserSample(array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->breadcrumb('browser.sample', [
            'reason' => $this->shortString($payload['reason'] ?? 'unknown', 64),
            'path' => $this->redactedUrlPath($payload['url'] ?? null),
            'path_hash' => $this->urlPathHash($payload['url'] ?? null),
            'hidden' => (bool) ($payload['hidden'] ?? false),
            'focused' => (bool) ($payload['focused'] ?? false),
            'viewport' => $this->arrayOnly($payload['viewport'] ?? null, ['width', 'height', 'devicePixelRatio']),
            'screen' => $this->arrayOnly($payload['screen'] ?? null, ['width', 'height', 'availWidth', 'availHeight']),
            'visibility' => $this->arrayOnly($payload['visibility'] ?? null, [
                'state',
                'hidden',
                'focused',
                'focusAgeMs',
                'visibilityAgeMs',
            ]),
            'activity' => $this->arrayOnly($payload['activity'] ?? null, ['idleMs', 'lastEvent']),
            'scroll' => $this->arrayOnly($payload['scroll'] ?? null, ['x', 'y', 'maxY']),
            'heap' => $this->arrayOnly($payload['heap'] ?? null, [
                'usedJSHeapSize',
                'totalJSHeapSize',
                'jsHeapSizeLimit',
                'usedJSHeapSizeMb',
                'totalJSHeapSizeMb',
            ]),
            'dom' => $this->arrayOnly($payload['dom'] ?? null, [
                'nodes',
                'livewireComponents',
                'diffFiles',
                'expandedDiffFiles',
                'diffLines',
                'comments',
                'animatedElements',
                'animateSpin',
                'animatePing',
                'animatePulse',
                'backdropBlur',
                'sticky',
            ]),
            'animations' => $this->normalizeAnimations($payload['animations'] ?? null),
            'navigation' => $this->arrayOnly($payload['navigation'] ?? null, [
                'type',
                'domCompleteMs',
                'resources',
            ]),
            'poll' => $this->arrayOnly($payload['poll'] ?? null, [
                'source',
                'method',
                'intervalMs',
                'ageMs',
                'hidden',
                'focused',
            ]),
            'timings' => $this->normalizeTimings($payload['timings'] ?? null),
        ]);

        if (($payload['includeProcessSnapshot'] ?? false) === true) {
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

        $processList = new Process(['ps', '-axo', 'pid=,ppid=,%cpu=,%mem=,rss=,stat=,etime=,comm=,command=']);
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

    /**
     * @param  array<string, mixed>|null  $value
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private function arrayOnly(mixed $value, array $keys): ?array
    {
        return is_array($value) ? Arr::only($value, $keys) : null;
    }

    /** @return array<string, mixed>|null */
    private function normalizeTimings(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return array_filter([
            'longTasks' => $this->arrayOnly($value['longTasks'] ?? null, ['count', 'totalMs', 'maxMs']),
            'longTasksDuringAction' => $this->arrayOnly($value['longTasksDuringAction'] ?? null, ['count', 'totalMs', 'maxMs']),
            'longTasksDuringCommit' => $this->arrayOnly($value['longTasksDuringCommit'] ?? null, ['count', 'totalMs', 'maxMs']),
            'diffAction' => $this->arrayOnly($value['diffAction'] ?? null, [
                'fileId',
                'action',
                'elapsedMs',
                'phpMs',
                'hunkCount',
                'diffLines',
                'lineContentBytes',
                'tooLarge',
                'binary',
                'cached',
            ]),
            'livewireCommit' => $this->arrayOnly($value['livewireCommit'] ?? null, [
                'status',
                'elapsedMs',
                'componentId',
                'componentName',
                'callCount',
                'calls',
                'updateCount',
                'updateKeys',
                'pollSource',
                'pollMethod',
                'pollAgeMs',
            ]),
        ], fn (mixed $item): bool => $item !== null);
    }

    /** @return array<string, mixed>|null */
    private function normalizeAnimations(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $summary = $this->arrayOnly($value, [
            'activeCount',
            'runningCount',
            'cssAnimationCount',
            'cssTransitionCount',
        ]) ?? [];
        $detailLimit = $this->animationDetailLimit();
        $classSummaryLimit = $this->animationClassSummaryLimit();

        $animations = array_filter([
            ...$summary,
            'classSummary' => $this->normalizeAnimationRows($value['classSummary'] ?? null, [
                'name',
                'count',
            ], $classSummaryLimit),
            'elementGroups' => $this->normalizeAnimationRows($value['elementGroups'] ?? null, [
                'signature',
                'count',
                'runningCount',
                'animationNames',
                'classes',
                'nearestLivewireName',
                'nearestTestId',
                'nearestInteractiveSignature',
                'nearestButtonLabel',
                'nearestButtonText',
                'nearestButtonTitle',
                'nearestButtonRole',
                'nearestButtonDisabled',
                'nearestLoading',
                'nearestWireClick',
                'nearestWireTarget',
            ], $detailLimit),
            'elements' => $this->normalizeAnimationRows($value['elements'] ?? null, [
                'signature',
                'tag',
                'id',
                'testId',
                'role',
                'classes',
                'animationNames',
                'playStates',
                'animationCount',
                'runningCount',
                'maxDurationMs',
                'connected',
                'visible',
                'nearestLivewireId',
                'nearestLivewireName',
                'nearestTestId',
                'nearestDiffFileState',
                'nearestInteractiveSignature',
                'nearestButtonLabel',
                'nearestButtonText',
                'nearestButtonTitle',
                'nearestButtonRole',
                'nearestButtonDisabled',
                'nearestLoading',
                'nearestWireClick',
                'nearestWireTarget',
                'rectX',
                'rectY',
                'rectWidth',
                'rectHeight',
                'computedDisplay',
                'computedVisibility',
                'computedOpacity',
                'computedPointerEvents',
                'cssAnimationName',
                'cssAnimationDuration',
                'cssAnimationPlayState',
            ], $detailLimit),
        ], fn (mixed $item): bool => $item !== null && $item !== []);

        return $animations === [] ? null : $animations;
    }

    private function animationDetailLimit(): int
    {
        return max(0, min(50, (int) config('rfa.diagnostics.animation_detail_limit', 20)));
    }

    private function animationClassSummaryLimit(): int
    {
        return max(0, min(50, (int) config('rfa.diagnostics.animation_class_summary_limit', 20)));
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>|null
     */
    private function normalizeAnimationRows(mixed $rows, array $keys, int $limit): ?array
    {
        if (! is_array($rows)) {
            return null;
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => array_filter(
                Arr::only($row, $keys),
                fn (mixed $item): bool => $item !== null && $item !== [],
            ))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    private function redactedUrlPath(mixed $url): ?string
    {
        $path = $this->urlPath($url);

        if ($path === null) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));

        if ($segments[0] === 'p' && isset($segments[1])) {
            $segments[1] = '{project}';
        }

        if (isset($segments[2], $segments[3]) && $segments[2] === 'c') {
            $segments[3] = '{hash}';
        }

        if (isset($segments[2], $segments[3]) && $segments[2] === 'r') {
            $segments[3] = '{range}';
        }

        if (isset($segments[2], $segments[3]) && $segments[2] === 'rw') {
            $segments[3] = '{range}';
        }

        return '/'.implode('/', array_filter($segments, fn (string $segment): bool => $segment !== ''));
    }

    private function urlPathHash(mixed $url): ?string
    {
        $path = $this->urlPath($url);

        return $path === null ? null : hash('xxh128', $path);
    }

    private function urlPath(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! Str::startsWith($path, '/')) {
            return null;
        }

        return $this->shortString($path, 256);
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

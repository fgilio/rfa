<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Assembles one launch timeline from the three processes that take part in
 * a cold start: the Electron main process (`rfa-launch.jsonl`), the PHP
 * server (`rfa-diagnostics.jsonl` breadcrumbs with request timing), and the
 * renderer (the `launch` browser sample in the same diagnostics log).
 *
 * Every mark is expressed in milliseconds since the main process was created,
 * so the three sources can be read as one ordered list.
 *
 * @phpstan-type Launch array{ts: string, version: string|null, packaged: bool, pid: int|null, t0_ms: int, marks: array<string, int>}
 */
final class LaunchTimelineService
{
    public const LAUNCH_FILE = 'rfa-launch.jsonl';

    public const DIAGNOSTICS_FILE = 'rfa-diagnostics.jsonl';

    /**
     * How long after process creation a diagnostics entry can still belong
     * to the launch. A launch that takes longer than this is a stuck launch,
     * and its late entries would only pollute the timeline.
     */
    private const LAUNCH_WINDOW_MS = 120_000;

    /**
     * The spans a launch is summarised into, as [from mark, to mark]. A
     * `null` start measures from process creation.
     *
     * @var array<string, array{0: string|null, 1: string}>
     */
    public const PHASES = [
        'electron: process -> bootstrap' => [null, 'bootstrap'],
        'electron: bootstrap -> app ready' => ['bootstrap', 'app.ready'],
        'php: spawn -> listening' => ['php.spawning', 'php.listening'],
        'php: listening -> warm request' => ['php.listening', 'php.warm.request'],
        'php: warm request -> warmed' => ['php.warm.request', 'php.warmed'],
        'splash: app ready -> shown' => ['app.ready', 'splash.shown'],
        'booted: sent -> php request' => ['booted.sent', 'php.booted.request'],
        'booted: php request -> handled' => ['php.booted.request', 'php.booted.handled'],
        'window: open -> created' => ['window.open', 'window.created'],
        'page: created -> php request' => ['window.created', 'php.page.request'],
        'page: php request -> mounted' => ['php.page.request', 'php.page.mounted'],
        'page: mounted -> response end' => ['php.page.mounted', 'renderer.response-end'],
        'renderer: response end -> dom ready' => ['renderer.response-end', 'window.dom-ready'],
        'renderer: dom ready -> loaded' => ['window.dom-ready', 'window.loaded'],
        'renderer: loaded -> livewire initialized' => ['window.loaded', 'renderer.livewire-initialized'],
        'renderer: livewire initialized -> fonts ready' => ['renderer.livewire-initialized', 'renderer.fonts-ready'],
        'renderer: livewire initialized -> first settled' => ['renderer.livewire-initialized', 'renderer.first-settled'],
        'renderer: first settled -> stable' => ['renderer.first-settled', 'renderer.stable'],
        'renderer: stable -> renderer ready' => ['renderer.stable', 'renderer.renderer-ready'],
        'renderer: livewire initialized -> renderer ready' => ['renderer.livewire-initialized', 'window.renderer-ready'],
        'window: renderer ready -> presented' => ['window.renderer-ready', 'window.presented'],
        'total: process -> presented' => [null, 'window.presented'],
    ];

    /**
     * The most recent launches in the log directory, newest last, each with
     * the PHP and renderer stamps merged in.
     *
     * @return list<Launch>
     */
    public function launches(string $logsDirectory, int $limit): array
    {
        $entries = $this->readJsonl($logsDirectory.'/'.self::DIAGNOSTICS_FILE);

        return $this->readJsonl($logsDirectory.'/'.self::LAUNCH_FILE)
            ->filter(fn (array $line): bool => ($line['event'] ?? null) === 'launch.timeline' && is_numeric($line['t0_ms'] ?? null))
            ->sortBy('t0_ms')
            ->take(-$limit)
            ->values()
            ->map(fn (array $line): array => $this->launch($line, $entries))
            ->all();
    }

    /**
     * The median of every mark across the launches, for marks present in
     * more than half of them.
     *
     * @param  list<Launch>  $launches
     * @return array<string, int>
     */
    public function medians(array $launches): array
    {
        if ($launches === []) {
            return [];
        }

        $required = intdiv(count($launches), 2) + 1;

        return collect($launches)
            ->flatMap(fn (array $launch): array => array_keys($launch['marks']))
            ->unique()
            ->mapWithKeys(function (string $mark) use ($launches): array {
                // Mark names carry dots, so they cannot go through pluck().
                $values = collect($launches)->map(fn (array $launch): mixed => $launch['marks'][$mark] ?? null)->filter(fn (mixed $value): bool => is_int($value));

                return [$mark => $values->count() > 0 ? (int) round($values->median()) : null];
            })
            ->filter(function (?int $median, string $mark) use ($launches, $required): bool {
                $present = collect($launches)->filter(fn (array $launch): bool => isset($launch['marks'][$mark]))->count();

                return $median !== null && $present >= $required;
            })
            ->sort()
            ->all();
    }

    /**
     * The phase durations of one set of marks (a launch, or the medians).
     *
     * @param  array<string, int>  $marks
     * @return array<string, int>
     */
    public function phases(array $marks): array
    {
        return collect(self::PHASES)
            ->map(function (array $span) use ($marks): ?int {
                [$from, $to] = $span;
                $start = $from === null ? 0 : ($marks[$from] ?? null);
                $end = $marks[$to] ?? null;

                return $start === null || $end === null ? null : $end - $start;
            })
            ->filter(fn (?int $duration): bool => $duration !== null)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Launch
     */
    private function launch(array $line, Collection $entries): array
    {
        // Electron reports process creation with sub-millisecond precision.
        $t0 = (int) round((float) $line['t0_ms']);
        $marks = collect(is_array($line['marks'] ?? null) ? $line['marks'] : [])
            ->filter(fn (mixed $value): bool => is_int($value) || is_float($value))
            ->map(fn (int|float $value): int => (int) round($value))
            ->all();

        $inWindow = $entries->filter(function (array $entry) use ($t0): bool {
            $at = $this->epochMs($entry['ts'] ?? null);

            return $at !== null && $at >= $t0 && $at <= $t0 + self::LAUNCH_WINDOW_MS;
        });

        $marks += $this->phpMarks($inWindow, $t0);
        $marks += $this->rendererMarks($inWindow, $t0);

        asort($marks);

        return [
            'ts' => (string) ($line['ts'] ?? ''),
            'version' => isset($line['version']) ? (string) $line['version'] : null,
            'packaged' => (bool) ($line['packaged'] ?? false),
            'pid' => is_int($line['pid'] ?? null) ? $line['pid'] : null,
            't0_ms' => $t0,
            'marks' => $marks,
        ];
    }

    /**
     * The first `booted` handshake, opcache warm-up, and page mount of the
     * launch, each as the request start and the moment the breadcrumb was
     * written.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function phpMarks(Collection $entries, int $t0): array
    {
        $stamps = [
            'php.warm' => fn (array $entry): bool => $entry['event'] === 'opcache.warmed',
            'php.booted' => fn (array $entry): bool => $entry['event'] === 'app.boot',
            'php.page' => fn (array $entry): bool => is_string($entry['event']) && str_starts_with($entry['event'], 'page.') && str_ends_with($entry['event'], '.mounted'),
        ];

        $marks = [];

        foreach ($stamps as $prefix => $matches) {
            $entry = $entries->first(fn (array $entry): bool => isset($entry['event']) && $matches($entry));

            if ($entry === null) {
                continue;
            }

            $handledAt = $this->epochMs($entry['ts'] ?? null);
            $startedAt = $entry['request']['started_at_ms'] ?? null;

            if (is_int($startedAt)) {
                $marks["{$prefix}.request"] = $startedAt - $t0;
            }

            if ($handledAt !== null) {
                $marks[$prefix.($prefix === 'php.page' ? '.mounted' : '.handled')] = $handledAt - $t0;
            }
        }

        return $marks;
    }

    /**
     * The renderer's navigation timing and settle points from the `launch`
     * browser sample, rebased from the document's time origin.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function rendererMarks(Collection $entries, int $t0): array
    {
        $sample = $entries->first(fn (array $entry): bool => ($entry['event'] ?? null) === 'browser.sample' && ($entry['context']['reason'] ?? null) === 'launch');

        if ($sample === null) {
            return [];
        }

        $navigation = $sample['context']['navigation'] ?? [];
        $launch = $sample['context']['timings']['launch'] ?? [];
        $origin = $navigation['timeOriginMs'] ?? null;

        if (! is_int($origin)) {
            return [];
        }

        $points = [
            'renderer.origin' => 0,
            'renderer.fetch-start' => $navigation['fetchStartMs'] ?? null,
            'renderer.response-start' => $navigation['responseStartMs'] ?? null,
            'renderer.response-end' => $navigation['responseEndMs'] ?? null,
            'renderer.dom-interactive' => $navigation['domInteractiveMs'] ?? null,
            'renderer.dom-content-loaded' => $navigation['domContentLoadedMs'] ?? null,
            'renderer.load-event-end' => $navigation['loadEventEndMs'] ?? null,
            'renderer.livewire-initialized' => $launch['livewireInitializedMs'] ?? null,
            'renderer.settle-start' => $launch['settleStartMs'] ?? null,
            'renderer.window-load' => $launch['windowLoadMs'] ?? null,
            'renderer.fonts-ready' => $launch['fontsReadyMs'] ?? null,
            'renderer.first-settled' => $launch['firstSettledMs'] ?? null,
            'renderer.stable' => $launch['stableMs'] ?? null,
            'renderer.renderer-ready' => $launch['rendererReadyMs'] ?? null,
        ];

        return collect($points)
            ->filter(fn (mixed $value): bool => is_int($value))
            ->map(fn (int $value): int => $origin + $value - $t0)
            ->all();
    }

    /**
     * Every JSON line of the file and its rotated siblings (`.1`, `.2`, ...),
     * oldest first. Unparseable lines are skipped: the logs are append-only
     * and a torn write only damages one entry.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function readJsonl(string $path): Collection
    {
        $paths = collect(File::glob($path.'.*') ?: [])
            ->filter(fn (string $rotated): bool => preg_match('/\.\d+$/', $rotated) === 1)
            ->sortByDesc(fn (string $rotated): int => (int) substr((string) strrchr($rotated, '.'), 1))
            ->push($path)
            ->filter(fn (string $candidate): bool => is_file($candidate));

        return $paths
            ->flatMap(fn (string $file): array => preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [])
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(fn (string $line): mixed => json_decode($line, true))
            ->filter(fn (mixed $decoded): bool => is_array($decoded))
            ->map(fn (array $decoded): array => $this->stringKeyed($decoded))
            ->values();
    }

    /**
     * A JSON object decoded to an array. A top-level JSON array has no
     * string keys and is dropped: no log entry is shaped that way.
     *
     * @param  array<mixed, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function stringKeyed(array $decoded): array
    {
        return collect($decoded)
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key))
            ->all();
    }

    private function epochMs(mixed $iso): ?int
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        $timestamp = strtotime($iso);

        if ($timestamp === false) {
            return null;
        }

        $fraction = preg_match('/\.(\d+)/', $iso, $match) === 1
            ? (int) round((float) ('0.'.$match[1]) * 1000)
            : 0;

        return $timestamp * 1000 + $fraction;
    }
}

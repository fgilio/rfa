<?php

declare(strict_types=1);

use App\Services\LaunchTimelineService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->logsDir = sys_get_temp_dir().'/rfa_test_launch_'.getmypid().'_'.uniqid('', true);
    File::ensureDirectoryExists($this->logsDir);
});

afterEach(function () {
    File::deleteDirectory($this->logsDir);
});

/**
 * One launch: the main process created at $t0 (epoch ms), the PHP stamps
 * and the renderer sample landing at fixed offsets after it.
 */
function writeLaunchFixture(string $dir, int $t0, array $marks = ['bootstrap' => 120, 'php.spawning' => 200, 'php.listening' => 400, 'app.ready' => 500, 'splash.shown' => 620, 'php.warmed' => 900, 'booted.sent' => 910, 'booted.acked' => 1000, 'window.open' => 990, 'window.created' => 1010, 'window.dom-ready' => 1300, 'window.loaded' => 1400, 'window.renderer-ready' => 1700, 'window.presented' => 1710]): void
{
    $iso = fn (int $epochMs): string => gmdate('Y-m-d\TH:i:s', intdiv($epochMs, 1000)).sprintf('.%03d000Z', $epochMs % 1000);

    File::append($dir.'/'.LaunchTimelineService::LAUNCH_FILE, json_encode([
        'ts' => $iso($t0 + 3000),
        'event' => 'launch.timeline',
        'pid' => 100,
        'version' => '1.2.3',
        'packaged' => true,
        't0_ms' => $t0,
        'marks' => $marks,
    ])."\n");

    $breadcrumb = fn (string $event, int $requestAt, int $handledAt, array $context = []): string => json_encode([
        'ts' => $iso($t0 + $handledAt),
        'event' => $event,
        'pid' => 200,
        'request' => ['started_at_ms' => $t0 + $requestAt, 'elapsed_ms' => $handledAt - $requestAt],
        'context' => $context,
    ])."\n";

    File::append($dir.'/'.LaunchTimelineService::DIAGNOSTICS_FILE, implode('', [
        $breadcrumb('opcache.warmed', 420, 880, ['compiled' => 300]),
        $breadcrumb('app.boot', 915, 995),
        $breadcrumb('page.review.mounted', 1020, 1140),
        $breadcrumb('browser.sample', 1750, 1760, [
            'reason' => 'launch',
            'navigation' => [
                'timeOriginMs' => $t0 + 1015,
                'fetchStartMs' => 2,
                'responseStartMs' => 130,
                'responseEndMs' => 140,
                'domInteractiveMs' => 250,
                'domContentLoadedMs' => 300,
                'domCompleteMs' => 380,
                'loadEventEndMs' => 385,
            ],
            'timings' => ['launch' => ['livewireInitializedMs' => 500, 'fontsReadyMs' => 520, 'firstSettledMs' => 600, 'stableMs' => 650, 'rendererReadyMs' => 680]],
        ]),
        // A sample from long after the launch window must not be attributed to it.
        $breadcrumb('page.review.mounted', 200_000, 200_100),
    ]));
}

test('a launch merges the main-process marks with the php and renderer stamps', function () {
    writeLaunchFixture($this->logsDir, 1_700_000_000_000);

    $launches = app(LaunchTimelineService::class)->launches($this->logsDir, 5);

    expect($launches)->toHaveCount(1)
        ->and($launches[0]['version'])->toBe('1.2.3')
        ->and($launches[0]['packaged'])->toBeTrue()
        ->and($launches[0]['marks'])->toMatchArray([
            'bootstrap' => 120,
            'php.listening' => 400,
            'php.warm.request' => 420,
            'php.warm.handled' => 880,
            'php.booted.request' => 915,
            'php.booted.handled' => 995,
            'php.page.request' => 1020,
            'php.page.mounted' => 1140,
            'renderer.origin' => 1015,
            'renderer.response-end' => 1155,
            'renderer.livewire-initialized' => 1515,
            'renderer.fonts-ready' => 1535,
            'renderer.stable' => 1665,
            'renderer.renderer-ready' => 1695,
            'window.presented' => 1710,
        ])
        ->and(array_keys($launches[0]['marks']))->toBe(array_keys(collect($launches[0]['marks'])->sort()->all()));
});

test('launches are the most recent ones, oldest first, across rotated files', function () {
    writeLaunchFixture($this->logsDir, 1_700_000_000_000);
    rename($this->logsDir.'/'.LaunchTimelineService::LAUNCH_FILE, $this->logsDir.'/'.LaunchTimelineService::LAUNCH_FILE.'.1');
    writeLaunchFixture($this->logsDir, 1_700_000_500_000);
    writeLaunchFixture($this->logsDir, 1_700_001_000_000);

    $launches = app(LaunchTimelineService::class)->launches($this->logsDir, 2);

    expect(array_column($launches, 't0_ms'))->toBe([1_700_000_500_000, 1_700_001_000_000]);
});

test('medians cover marks present in more than half of the launches', function () {
    $service = app(LaunchTimelineService::class);
    $launches = [
        ['ts' => '', 'version' => null, 'packaged' => true, 'pid' => null, 't0_ms' => 0, 'marks' => ['bootstrap' => 100, 'window.presented' => 1000, 'php.migrate.started' => 300]],
        ['ts' => '', 'version' => null, 'packaged' => true, 'pid' => null, 't0_ms' => 0, 'marks' => ['bootstrap' => 140, 'window.presented' => 1200]],
        ['ts' => '', 'version' => null, 'packaged' => true, 'pid' => null, 't0_ms' => 0, 'marks' => ['bootstrap' => 120, 'window.presented' => 1100]],
    ];

    expect($service->medians($launches))->toBe(['bootstrap' => 120, 'window.presented' => 1100])
        ->and($service->medians([]))->toBe([]);
});

test('phases are the spans between marks and skip spans with a missing end', function () {
    $phases = app(LaunchTimelineService::class)->phases([
        'bootstrap' => 120,
        'app.ready' => 500,
        'php.optimize.started' => 190,
        'php.spawning' => 200,
        'php.listening' => 400,
        'window.presented' => 1710,
        'php.optimize.finished' => 1900,
    ]);

    expect($phases)->toBe([
        'electron: process -> bootstrap' => 120,
        'electron: bootstrap -> app ready' => 380,
        'php: optimize started -> finished (background)' => 1710,
        'php: spawn -> listening' => 200,
        'total: process -> presented' => 1710,
    ]);
});

test('the report command prints the marks and phases', function () {
    writeLaunchFixture($this->logsDir, 1_700_000_000_000);

    $this->artisan('rfa:launch-report', ['--logs' => $this->logsDir])
        ->expectsOutputToContain('window.presented')
        ->expectsOutputToContain('total: process -> presented')
        ->assertSuccessful();

    $this->artisan('rfa:launch-report', ['--logs' => $this->logsDir, '--json' => true])
        ->expectsOutputToContain('"php.page.mounted": 1140')
        ->assertSuccessful();
});

test('the report command fails clearly when there is no timeline yet', function () {
    $this->artisan('rfa:launch-report', ['--logs' => $this->logsDir])
        ->expectsOutputToContain('No launch timeline found')
        ->assertFailed();
});

test('corrupt lines in either log are skipped', function () {
    writeLaunchFixture($this->logsDir, 1_700_000_000_000);
    File::append($this->logsDir.'/'.LaunchTimelineService::LAUNCH_FILE, "{not json\n");
    File::append($this->logsDir.'/'.LaunchTimelineService::DIAGNOSTICS_FILE, "{not json\n");

    expect(app(LaunchTimelineService::class)->launches($this->logsDir, 5))->toHaveCount(1);
});

<?php

use App\Services\RuntimeDiagnosticsService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->diagnosticsDir = sys_get_temp_dir().'/rfa-diagnostics-test-'.getmypid().'-'.uniqid('', true);
    $this->diagnosticsPath = $this->diagnosticsDir.'/diagnostics.jsonl';

    config([
        'rfa.diagnostics.enabled' => true,
        'rfa.diagnostics.path' => $this->diagnosticsPath,
        'rfa.diagnostics.max_file_bytes' => 1024 * 1024,
        'rfa.diagnostics.max_files' => 2,
    ]);
});

afterEach(function () {
    foreach (glob($this->diagnosticsPath.'*') ?: [] as $path) {
        @unlink($path);
    }

    foreach (glob($this->diagnosticsDir.'/bin/*') ?: [] as $path) {
        @unlink($path);
    }

    if (is_dir($this->diagnosticsDir.'/bin')) {
        @rmdir($this->diagnosticsDir.'/bin');
    }

    if (is_dir($this->diagnosticsDir)) {
        @rmdir($this->diagnosticsDir);
    }
});

test('breadcrumb writes one json line with php memory context', function () {
    app(RuntimeDiagnosticsService::class)->breadcrumb('review.opened', [
        'project_slug' => 'rfa',
        'long' => str_repeat('a', 700),
    ]);

    $entry = json_decode(trim((string) file_get_contents($this->diagnosticsPath)), true);

    expect($entry['event'])->toBe('review.opened')
        ->and($entry['pid'])->toBeInt()
        ->and($entry['php']['memory_mb'])->toBeNumeric()
        ->and($entry['context']['project_slug'])->toBe('rfa')
        ->and(strlen($entry['context']['long']))->toBeLessThanOrEqual(500);
});

test('breadcrumb stamps the request start and elapsed time in epoch milliseconds', function () {
    $requestStartedAt = $_SERVER['REQUEST_TIME_FLOAT'];

    app(RuntimeDiagnosticsService::class)->breadcrumb('review.opened');

    $entry = json_decode(trim((string) file_get_contents($this->diagnosticsPath)), true);

    expect($entry['request']['started_at_ms'])->toBe((int) round($requestStartedAt * 1000))
        ->and($entry['request']['elapsed_ms'])->toBeGreaterThanOrEqual(0)
        ->and($entry['request']['elapsed_ms'])->toBeLessThan(60_000);
});

test('disabled diagnostics do not touch the filesystem', function () {
    config(['rfa.diagnostics.enabled' => false]);

    app(RuntimeDiagnosticsService::class)->breadcrumb('review.opened');

    expect(is_file($this->diagnosticsPath))->toBeFalse();
});

test('breadcrumb rotates files by configured size', function () {
    mkdir($this->diagnosticsDir, 0755, true);
    file_put_contents($this->diagnosticsPath, str_repeat('x', 128));

    config(['rfa.diagnostics.max_file_bytes' => 16]);

    app(RuntimeDiagnosticsService::class)->breadcrumb('after.rotate');

    expect(is_file($this->diagnosticsPath))->toBeTrue()
        ->and(is_file($this->diagnosticsPath.'.1'))->toBeTrue()
        ->and(file_get_contents($this->diagnosticsPath.'.1'))->toBe(str_repeat('x', 128));
});

test('browser sample records useful counters without query strings', function () {
    app(RuntimeDiagnosticsService::class)->recordBrowserSample([
        'reason' => 'heartbeat',
        'url' => 'http://127.0.0.1:8100/p/rfa/c/abcdef?token=secret',
        'hidden' => false,
        'focused' => true,
        'viewport' => ['width' => 1280, 'height' => 860, 'devicePixelRatio' => 2],
        'screen' => ['width' => 2560, 'height' => 1600, 'availWidth' => 2560, 'availHeight' => 1512],
        'visibility' => ['state' => 'visible', 'hidden' => false, 'focused' => true, 'focusAgeMs' => 1200],
        'activity' => ['idleMs' => 45000, 'lastEvent' => 'keydown'],
        'scroll' => ['x' => 0, 'y' => 240, 'maxY' => 2000],
        'heap' => ['usedJSHeapSizeMb' => 123.456, 'totalJSHeapSizeMb' => 150.0],
        'dom' => [
            'nodes' => 5000,
            'livewireComponents' => 120,
            'diffFiles' => 30,
            'expandedDiffFiles' => 12,
            'animatedElements' => 2,
            'animateSpin' => 1,
        ],
        'animations' => [
            'activeCount' => 4,
            'runningCount' => 3,
            'cssAnimationCount' => 3,
            'cssTransitionCount' => 1,
            'classSummary' => [
                ['name' => 'animate-spin', 'count' => 3],
                ['name' => 'animate-pulse', 'count' => 1],
            ],
            'elementGroups' => [
                [
                    'signature' => 'svg.animate-spin',
                    'count' => 3,
                    'runningCount' => 3,
                    'animationNames' => ['spin'],
                    'classes' => ['animate-spin'],
                    'nearestLivewireName' => 'update-banner',
                    'nearestTestId' => 'update-banner',
                    'nearestInteractiveSignature' => 'button[data-testid="refresh-button"]',
                    'nearestButtonLabel' => 'Refresh changes',
                    'nearestButtonText' => 'Refresh',
                    'nearestButtonTitle' => 'Check changes',
                    'nearestButtonRole' => 'button',
                    'nearestButtonDisabled' => false,
                    'nearestLoading' => true,
                    'nearestWireClick' => 'softRefresh',
                    'nearestWireTarget' => 'softRefresh',
                ],
            ],
            'elements' => [
                [
                    'signature' => 'svg.animate-spin',
                    'tag' => 'svg',
                    'id' => 'refresh-icon',
                    'testId' => 'refresh-icon',
                    'classes' => ['animate-spin'],
                    'animationNames' => ['spin'],
                    'playStates' => ['running'],
                    'animationCount' => 1,
                    'runningCount' => 1,
                    'maxDurationMs' => 1000,
                    'connected' => true,
                    'visible' => true,
                    'nearestLivewireId' => 'abc123',
                    'nearestLivewireName' => 'update-banner',
                    'nearestTestId' => 'update-banner',
                    'nearestDiffFileState' => 'false',
                    'nearestInteractiveSignature' => 'button[data-testid="refresh-button"]',
                    'nearestButtonLabel' => 'Refresh changes',
                    'nearestButtonText' => 'Refresh',
                    'nearestButtonTitle' => 'Check changes',
                    'nearestButtonRole' => 'button',
                    'nearestButtonDisabled' => false,
                    'nearestLoading' => true,
                    'nearestWireClick' => 'softRefresh',
                    'nearestWireTarget' => 'softRefresh',
                    'rectX' => 10,
                    'rectY' => 20,
                    'rectWidth' => 16,
                    'rectHeight' => 16,
                    'computedDisplay' => 'block',
                    'computedVisibility' => 'visible',
                    'computedOpacity' => '1',
                    'computedPointerEvents' => 'auto',
                    'cssAnimationName' => 'spin',
                    'cssAnimationDuration' => '1s',
                    'cssAnimationPlayState' => 'running',
                ],
            ],
        ],
        'navigation' => ['type' => 'navigate', 'resources' => 45],
        'poll' => ['source' => 'wire:smart-poll:review-page', 'method' => 'poll', 'intervalMs' => 10000, 'ageMs' => 65],
        'timings' => [
            'diffAction' => ['action' => 'expandContext', 'elapsedMs' => 2400, 'phpMs' => 2200, 'diffLines' => 2247],
            'longTasksDuringAction' => ['count' => 3, 'totalMs' => 180, 'maxMs' => 90],
        ],
    ]);

    $entry = json_decode(trim((string) file_get_contents($this->diagnosticsPath)), true);

    expect($entry['event'])->toBe('browser.sample')
        ->and($entry['context']['path'])->toBe('/p/{project}/c/{hash}')
        ->and($entry['context']['path_hash'])->toBe(hash('xxh128', '/p/rfa/c/abcdef'))
        ->and($entry['context']['heap']['usedJSHeapSizeMb'])->toBe(123.456)
        ->and($entry['context']['dom']['diffFiles'])->toBe(30)
        ->and($entry['context']['dom']['animateSpin'])->toBe(1)
        ->and($entry['context']['animations']['activeCount'])->toBe(4)
        ->and($entry['context']['animations']['classSummary'][0])->toBe(['name' => 'animate-spin', 'count' => 3])
        ->and($entry['context']['animations']['elementGroups'][0]['nearestLivewireName'])->toBe('update-banner')
        ->and($entry['context']['animations']['elementGroups'][0]['nearestButtonLabel'])->toBe('Refresh changes')
        ->and($entry['context']['animations']['elements'][0]['signature'])->toBe('svg.animate-spin')
        ->and($entry['context']['animations']['elements'][0]['nearestWireClick'])->toBe('softRefresh')
        ->and($entry['context']['animations']['elements'][0]['rectWidth'])->toBe(16)
        ->and($entry['context']['visibility']['focusAgeMs'])->toBe(1200)
        ->and($entry['context']['activity']['idleMs'])->toBe(45000)
        ->and($entry['context']['scroll']['y'])->toBe(240)
        ->and($entry['context']['poll']['source'])->toBe('wire:smart-poll:review-page')
        ->and($entry['context']['timings']['diffAction']['phpMs'])->toBe(2200)
        ->and($entry['context']['timings']['longTasksDuringAction']['count'])->toBe(3)
        ->and($entry['context']['viewport']['width'])->toBe(1280);
});

test('browser sample does not leak process snapshot timeouts', function () {
    $originalPath = getenv('PATH') ?: '';
    $fakeBin = $this->diagnosticsDir.'/bin';
    $fakePs = $fakeBin.'/ps';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakePs, "#!/bin/sh\nsleep 1\n");
    chmod($fakePs, 0755);

    config([
        'rfa.diagnostics.process_snapshots' => true,
        'rfa.diagnostics.process_snapshot_timeout_seconds' => 0.01,
    ]);

    putenv('PATH='.$fakeBin.':'.$originalPath);

    try {
        app(RuntimeDiagnosticsService::class)->recordBrowserSample([
            'reason' => 'heartbeat',
            'includeProcessSnapshot' => true,
        ]);
    } finally {
        putenv('PATH='.$originalPath);
    }

    $entries = collect(file($this->diagnosticsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->map(fn (string $line): array => json_decode($line, true))
        ->all();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['event'])->toBe('browser.sample')
        ->and($entries[1]['event'])->toBe('system.processes')
        ->and($entries[1]['context']['processes'])->toBe([]);
});

test('process snapshots include cpu counters and sanitized command features', function () {
    $originalPath = getenv('PATH') ?: '';
    $fakeBin = $this->diagnosticsDir.'/bin';
    $fakePs = $fakeBin.'/ps';

    mkdir($fakeBin, 0755, true);
    file_put_contents($fakePs, <<<'SH'
#!/bin/sh
printf ' 123 1 12.5 3.4 204800 S 01:02:03 /Applications/rfa.app/Contents/MacOS/rfa /Applications/rfa.app/Contents/MacOS/rfa --type=renderer --enable-features=Foo,Bar --disable-features=MacWebContentsOcclusion,Other\n'
SH);
    chmod($fakePs, 0755);

    config([
        'rfa.diagnostics.process_snapshots' => true,
        'rfa.diagnostics.process_snapshot_timeout_seconds' => 1,
    ]);

    putenv('PATH='.$fakeBin.':'.$originalPath);

    try {
        app(RuntimeDiagnosticsService::class)->recordBrowserSample([
            'reason' => 'heartbeat',
            'includeProcessSnapshot' => true,
        ]);
    } finally {
        putenv('PATH='.$originalPath);
    }

    $entries = collect(file($this->diagnosticsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->map(fn (string $line): array => json_decode($line, true))
        ->all();

    $process = $entries[1]['context']['processes'][0];

    expect($process['pid'])->toBe(123)
        ->and($process['role'])->toBe('renderer')
        ->and($process['cpu_percent'])->toBe(12.5)
        ->and($process['memory_percent'])->toBe(3.4)
        ->and($process['rss_mb'])->toBe(200)
        ->and($process['state'])->toBe('S')
        ->and($process['elapsed'])->toBe('01:02:03')
        ->and($process['command_hash'])->toHaveLength(32)
        ->and($process['command_features']['type'])->toBe('renderer')
        ->and($process['command_features']['enabled'])->toBe(['Foo', 'Bar'])
        ->and($process['command_features']['disabled'])->toContain('MacWebContentsOcclusion');
});

test('process snapshots force the C locale so comma-decimal locales still parse', function () {
    $originalPath = getenv('PATH') ?: '';
    $originalNumeric = getenv('LC_NUMERIC');
    $fakeBin = $this->diagnosticsDir.'/bin';
    $fakePs = $fakeBin.'/ps';

    // Emits comma decimals (12,5) unless the C locale was forced — mimicking a
    // host whose LC_NUMERIC uses a comma. Without the LC_ALL=C override the
    // %cpu/%mem capture groups would never match and the snapshot would be empty.
    mkdir($fakeBin, 0755, true);
    file_put_contents($fakePs, <<<'SH'
#!/bin/sh
if [ "$LC_ALL" = "C" ]; then
    printf ' 123 1 12.5 3.4 204800 S 01:02:03 /Applications/rfa.app/Contents/MacOS/rfa /Applications/rfa.app/Contents/MacOS/rfa --type=renderer\n'
else
    printf ' 123 1 12,5 3,4 204800 S 01:02:03 /Applications/rfa.app/Contents/MacOS/rfa /Applications/rfa.app/Contents/MacOS/rfa --type=renderer\n'
fi
SH);
    chmod($fakePs, 0755);

    config([
        'rfa.diagnostics.process_snapshots' => true,
        'rfa.diagnostics.process_snapshot_timeout_seconds' => 1,
    ]);

    putenv('PATH='.$fakeBin.':'.$originalPath);
    putenv('LC_NUMERIC=de_DE.UTF-8');

    try {
        app(RuntimeDiagnosticsService::class)->recordBrowserSample([
            'reason' => 'heartbeat',
            'includeProcessSnapshot' => true,
        ]);
    } finally {
        putenv('PATH='.$originalPath);
        putenv($originalNumeric === false ? 'LC_NUMERIC' : 'LC_NUMERIC='.$originalNumeric);
    }

    $entries = collect(file($this->diagnosticsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->map(fn (string $line): array => json_decode($line, true))
        ->all();

    $process = $entries[1]['context']['processes'][0];

    expect($process['pid'])->toBe(123)
        ->and($process['cpu_percent'])->toBe(12.5)
        ->and($process['memory_percent'])->toBe(3.4);
});

test('browser sample drops urls without a path', function () {
    app(RuntimeDiagnosticsService::class)->recordBrowserSample([
        'reason' => 'heartbeat',
        'url' => '?token=secret',
    ]);

    $entry = json_decode(trim((string) file_get_contents($this->diagnosticsPath)), true);

    expect($entry['context']['path'])->toBeNull()
        ->and($entry['context']['path_hash'])->toBeNull();
});

test('breadcrumb substitutes invalid utf8 without throwing', function () {
    app(RuntimeDiagnosticsService::class)->breadcrumb('diff.loaded', [
        'path' => "bad-\xB1-byte.php",
    ]);

    $entry = json_decode(trim((string) file_get_contents($this->diagnosticsPath)), true);

    expect($entry['event'])->toBe('diff.loaded')
        ->and($entry['context']['path'])->toContain('bad-');
});

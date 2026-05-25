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
        'heap' => ['usedJSHeapSizeMb' => 123.456, 'totalJSHeapSizeMb' => 150.0],
        'dom' => ['nodes' => 5000, 'livewireComponents' => 120, 'diffFiles' => 30, 'expandedDiffFiles' => 12],
        'navigation' => ['type' => 'navigate', 'resources' => 45],
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

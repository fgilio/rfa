<?php

use App\Console\Benchmark\PerfBenchmarkReport;

function perfSnapshotFile(mixed $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'rfa-perf-snapshot-'.getmypid().'-');

    if ($path === false) {
        throw new RuntimeException('Unable to allocate benchmark snapshot path.');
    }

    file_put_contents($path, is_string($contents) ? $contents : json_encode($contents, JSON_THROW_ON_ERROR));

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/rfa-perf-snapshot-'.getmypid().'-*') ?: [] as $path) {
        @unlink($path);
    }
});

test('a report round trips through the codec with its schema version', function () {
    $report = [
        'generated_at' => '2026-01-01T00:00:00+00:00',
        'config' => ['samples' => 5],
        'results' => [
            'diff-small' => [
                'median_ms' => 41.321,
                'samples_ms' => [42.439, 40.203],
                'median_peak_mb' => 4.25,
                'samples_peak_mb' => [4.25, 4.5],
                'median_retained_mb' => 2.5,
                'samples_retained_mb' => [2.5, 2.5],
            ],
        ],
    ];

    $decoded = json_decode(PerfBenchmarkReport::encode($report), true);

    expect($decoded)->toBe(['schema_version' => PerfBenchmarkReport::SCHEMA_VERSION, ...$report]);
});

test('decodes a child sample written by this build', function () {
    $sample = PerfBenchmarkReport::decodeChildSample(PerfBenchmarkReport::encode([
        'generated_at' => '2026-01-01T00:00:00+00:00',
        'results' => [
            'diff-small' => ['median_ms' => 41.321, 'median_peak_mb' => 4.0, 'median_retained_mb' => 2.5],
        ],
    ]));

    expect($sample)->toBe([
        'generated_at' => '2026-01-01T00:00:00+00:00',
        'results' => [
            'diff-small' => ['median_ms' => 41.321, 'median_peak_mb' => 4.0, 'median_retained_mb' => 2.5],
        ],
    ]);
});

test('rejects child output that is not a json report', function () {
    PerfBenchmarkReport::decodeChildSample("PHP Warning: something went wrong\n");
})->throws(RuntimeException::class, 'The benchmark child process did not print a JSON report.');

test('rejects a child report without results', function () {
    PerfBenchmarkReport::decodeChildSample(PerfBenchmarkReport::encode(['generated_at' => 'now']));
})->throws(RuntimeException::class, 'The benchmark child process report is missing its results.');

test('rejects a child report with a missing metric instead of reading it as zero', function () {
    PerfBenchmarkReport::decodeChildSample(PerfBenchmarkReport::encode([
        'generated_at' => 'now',
        'results' => ['diff-small' => ['median_ms' => 41.321, 'median_peak_mb' => 4.0]],
    ]));
})->throws(RuntimeException::class, 'Scenario [diff-small] in the benchmark child process report is missing a numeric median_retained_mb metric.');

test('rejects a child report from an unsupported schema version', function () {
    PerfBenchmarkReport::decodeChildSample((string) json_encode([
        'schema_version' => 99,
        'results' => [],
    ]));
})->throws(RuntimeException::class, 'Unsupported schema version [99] in the benchmark child process report.');

test('decodes a snapshot this build wrote', function () {
    $path = perfSnapshotFile(PerfBenchmarkReport::encode([
        'generated_at' => 'now',
        'results' => [
            'diff-small' => [
                'median_ms' => 41.321,
                'samples_ms' => [41.321],
                'median_peak_mb' => 4.0,
                'samples_peak_mb' => [4.0],
                'median_retained_mb' => 2.5,
                'samples_retained_mb' => [2.5],
            ],
        ],
    ]));

    expect(PerfBenchmarkReport::decodeSnapshot($path))->toBe([
        'schema_version' => PerfBenchmarkReport::SCHEMA_VERSION,
        'results' => [
            'diff-small' => ['median_ms' => 41.321, 'median_peak_mb' => 4.0, 'median_retained_mb' => 2.5],
        ],
    ]);
});

test('reads an unversioned snapshot through the version 0 path', function () {
    $path = perfSnapshotFile([
        'generated_at' => 'now',
        'results' => [
            'diff-small' => ['median_ms' => 41.321, 'median_peak_mb' => 4.0, 'median_retained_mb' => 2.5],
            'diff-large' => ['median_ms' => 180.0],
            'drawer-reply-filter' => 12.5,
        ],
    ]);

    expect(PerfBenchmarkReport::decodeSnapshot($path))->toBe([
        'schema_version' => 0,
        'results' => [
            'diff-small' => ['median_ms' => 41.321, 'median_peak_mb' => 4.0, 'median_retained_mb' => 2.5],
            'diff-large' => ['median_ms' => 180.0, 'median_peak_mb' => null, 'median_retained_mb' => null],
            'drawer-reply-filter' => ['median_ms' => 12.5, 'median_peak_mb' => null, 'median_retained_mb' => null],
        ],
    ]);
});

test('reads the empty baseline CI writes when the base branch has no benchmark', function () {
    $path = perfSnapshotFile('{"generated_at":"bootstrap","results":{}}');

    expect(PerfBenchmarkReport::decodeSnapshot($path)['results'])->toBe([]);
});

test('rejects an unversioned snapshot scenario without a median time', function () {
    $path = perfSnapshotFile([
        'results' => ['diff-small' => ['median_peak_mb' => 4.0]],
    ]);

    PerfBenchmarkReport::decodeSnapshot($path);
})->throws(RuntimeException::class, 'is missing a numeric median_ms metric');

test('rejects a malformed snapshot file', function () {
    $path = perfSnapshotFile('{"results": ');

    PerfBenchmarkReport::decodeSnapshot($path);
})->throws(RuntimeException::class, 'Benchmark snapshot is not valid JSON');

test('rejects a snapshot without results', function () {
    $path = perfSnapshotFile(['generated_at' => 'now']);

    PerfBenchmarkReport::decodeSnapshot($path);
})->throws(RuntimeException::class, 'Benchmark snapshot is missing its results');

test('rejects a snapshot from an unsupported schema version', function () {
    $path = perfSnapshotFile(['schema_version' => 99, 'results' => []]);

    PerfBenchmarkReport::decodeSnapshot($path);
})->throws(RuntimeException::class, 'Unsupported schema version [99]');

test('rejects a missing snapshot file', function () {
    PerfBenchmarkReport::decodeSnapshot(sys_get_temp_dir().'/rfa-perf-snapshot-'.getmypid().'-absent.json');
})->throws(RuntimeException::class, 'Benchmark snapshot not found');

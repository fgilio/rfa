<?php

/**
 * Compares [PERF] output from before/after benchmark runs.
 *
 * Usage: php tests/Helpers/compare-perf.php .perf/before.log .perf/after.log
 */

// Only run when invoked directly from CLI
if (! isset($argc) || php_sapi_name() !== 'cli' || $argc < 3 || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} <before.log> <after.log>\n");
    exit(1);
}

$beforeFile = $argv[1];
$afterFile = $argv[2];

if (! file_exists($beforeFile)) {
    fwrite(STDERR, "File not found: {$beforeFile}\n");
    exit(1);
}

if (! file_exists($afterFile)) {
    fwrite(STDERR, "File not found: {$afterFile}\n");
    exit(1);
}

function parsePerfLines(string $file): array
{
    $results = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        if (preg_match('/\[PERF\]\s+(.+?):\s+([\d.]+)ms/', $line, $m)) {
            $results[$m[1]] = (float) $m[2];
        }
    }

    return $results;
}

$before = parsePerfLines($beforeFile);
$after = parsePerfLines($afterFile);

$allTests = array_unique(array_merge(array_keys($before), array_keys($after)));
sort($allTests);

if (empty($allTests)) {
    echo "No [PERF] entries found in either file.\n";
    exit(0);
}

// Calculate column widths
$nameWidth = max(4, ...array_map('strlen', $allTests));
$nameWidth = min($nameWidth, 60);

$separator = str_repeat('-', $nameWidth + 42);

echo "\n";
printf("  %-{$nameWidth}s  %10s  %10s  %10s\n", 'Test', 'Before', 'After', 'Change');
echo "  {$separator}\n";

foreach ($allTests as $test) {
    $b = $before[$test] ?? null;
    $a = $after[$test] ?? null;

    $beforeStr = $b !== null ? sprintf('%.1fms', $b) : '-';
    $afterStr = $a !== null ? sprintf('%.1fms', $a) : '-';

    if ($b !== null && $a !== null && $b > 0) {
        $pct = (($a - $b) / $b) * 100;
        $sign = $pct <= 0 ? '' : '+';
        $changeStr = sprintf('%s%.0f%%', $sign, $pct);
    } else {
        $changeStr = '-';
    }

    printf("  %-{$nameWidth}s  %10s  %10s  %10s\n", substr($test, 0, $nameWidth), $beforeStr, $afterStr, $changeStr);
}

echo "\n";

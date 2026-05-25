<?php

beforeEach(function () {
    $this->diagnosticsDir = sys_get_temp_dir().'/rfa-browser-perf-'.getmypid().'-'.uniqid('', true);
    $this->diagnosticsPath = $this->diagnosticsDir.'/diagnostics.jsonl';

    config([
        'rfa.diagnostics.enabled' => true,
        'rfa.diagnostics.path' => $this->diagnosticsPath,
    ]);

    $this->setUpLargeBladeDiffRepo();
});

afterEach(function () {
    foreach (glob($this->diagnosticsPath.'*') ?: [] as $path) {
        @unlink($path);
    }

    if (is_dir($this->diagnosticsDir)) {
        @rmdir($this->diagnosticsDir);
    }
});

test('large Blade full context records browser and PHP timings', function () {
    $page = $this->visit($this->projectUrl());

    $page->page()->getByRole('button', ['name' => 'Show full file'])->waitFor();
    $page->page()->getByRole('button', ['name' => 'Show full file'])->click();

    $metrics = null;
    $deadline = microtime(true) + 20;

    do {
        $metrics = json_decode($page->script(<<<'JS'
            JSON.stringify({
                timing: window.__rfaDiffActionTimings?.expandContext ?? null,
                sample: window.rfaRuntimeDiagnostics.collectSample(window, 'manual', false),
            })
        JS), true);

        if (($metrics['timing'] ?? null) !== null && ($metrics['sample']['dom']['diffLines'] ?? 0) > 2000) {
            break;
        }

        usleep(100_000);
    } while (microtime(true) < $deadline);

    expect($metrics['timing'] ?? null)->toBeArray()
        ->and($metrics['timing']['action'])->toBe('expandContext')
        ->and($metrics['timing']['phpMs'])->toBeInt()->toBeGreaterThan(0)
        ->and($metrics['timing']['elapsedMs'])->toBeInt()->toBeGreaterThan(0)
        ->and($metrics['timing']['diffLines'])->toBeGreaterThan(2000)
        ->and($metrics['sample']['dom']['diffLines'])->toBeGreaterThan(2000)
        ->and($metrics['sample']['timings'])->toHaveKey('longTasks');
})->skip(getenv('RFA_RUN_BROWSER_PERF') !== '1', 'Set RFA_RUN_BROWSER_PERF=1 to run the large diff browser perf probe.');

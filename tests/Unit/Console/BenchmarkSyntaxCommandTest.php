<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

test('syntax benchmark compares adapters and reports reproducible samples', function () {
    Artisan::call('rfa:benchmark-syntax', [
        'files' => ['composer.json'],
        '--json' => true,
        '--samples' => 1,
        '--rounds' => 1,
        '--warmup-rounds' => 0,
    ]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report)->toHaveKeys(['php', 'platform', 'phiki', 'tempest', 'results'])
        ->and($report['results'])->toHaveCount(2)
        ->and(array_column($report['results'], 'actual_highlighter'))->toBe(['tempest', 'phiki']);

    foreach ($report['results'] as $result) {
        expect($result['sha256'])->toBe(hash_file('sha256', base_path('composer.json')))
            ->and($result['median_ms'])->toBeGreaterThan(0)
            ->and($result['median_peak_mb'])->toBeGreaterThan(0)
            ->and($result['samples'])->toHaveCount(1)
            ->and($result['samples'][0]['rounds_ms'])->toHaveCount(1);
    }
});

test('syntax benchmark rejects invalid options', function (array $options) {
    $this->artisan('rfa:benchmark-syntax', ['files' => ['composer.json'], ...$options])->assertExitCode(2);
})->with([
    [['--rounds' => 0]],
    [['--samples' => '1.5']],
    [['--warmup-rounds' => -1]],
    [['--backend' => ['unknown']]],
]);

test('syntax benchmark rejects a missing source', function () {
    $this->artisan('rfa:benchmark-syntax', ['files' => ['missing-source.php']])->assertExitCode(2);
});

test('syntax benchmark runs the PCRE backend in a child process', function () {
    Artisan::call('rfa:benchmark-syntax', [
        'files' => ['composer.json'],
        '--backend' => ['phiki-pcre'],
        '--samples' => 1,
        '--rounds' => 1,
        '--warmup-rounds' => 0,
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['results'])->toHaveCount(1)
        ->and($report['results'][0]['backend'])->toBe('phiki-pcre')
        ->and($report['results'][0]['actual_highlighter'])->toBe('phiki');
});

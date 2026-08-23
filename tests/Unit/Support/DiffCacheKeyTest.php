<?php

use App\DTOs\ReviewConfig;
use App\Services\ReviewConfigService;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

/** Resolve from a fresh service so a test's config changes are not masked by memoization. */
function fingerprint(): string
{
    return (new ReviewConfigService)->resolve()->movedLineFingerprint();
}

test('for is deterministic and varies by project, file, context, and review settings', function () {
    $a = DiffCacheKey::for(1, 'file-a', 'm0');
    $b = DiffCacheKey::for(1, 'file-a', 'm0');
    $c = DiffCacheKey::for(2, 'file-a', 'm0');
    $d = DiffCacheKey::for(1, 'file-b', 'm0');
    $e = DiffCacheKey::for(1, 'file-a', 'm0', 'HEAD..abc');
    $f = DiffCacheKey::for(1, 'file-a', 'm1-zebra');

    expect($a)->toBe($b)
        ->and($a)->not->toBe($c)
        ->and($a)->not->toBe($d)
        ->and($a)->not->toBe($e)
        ->and($a)->not->toBe($f);
});

test('forget clears every variant of a file diff', function () {
    foreach (DiffCacheKey::VARIANTS as $variant) {
        Cache::put(DiffCacheKey::for(7, 'file-x', 'm0', 'HEAD..working'.$variant), 'stale', 60);
    }

    DiffCacheKey::forget(7, 'file-x', 'm0', 'HEAD..working');

    foreach (DiffCacheKey::VARIANTS as $variant) {
        expect(Cache::has(DiffCacheKey::for(7, 'file-x', 'm0', 'HEAD..working'.$variant)))->toBeFalse();
    }
});

test('VARIANTS includes the base and full-context shapes', function () {
    expect(DiffCacheKey::VARIANTS)->toContain('')->toContain(':full-context');
});

test('the fingerprint varies by the moved-line detection settings', function () {
    config(['rfa.moved_lines.enabled' => false]);
    $off = fingerprint();

    config(['rfa.moved_lines.enabled' => true, 'rfa.moved_lines.mode' => 'zebra']);
    $onZebra = fingerprint();

    config(['rfa.moved_lines.mode' => 'blocks']);
    $onBlocks = fingerprint();

    expect($off)->not->toBe($onZebra)
        ->and($onZebra)->not->toBe($onBlocks);
});

test('the fingerprint ignores the moved-line mode while detection is off', function () {
    config(['rfa.moved_lines.enabled' => false, 'rfa.moved_lines.mode' => 'zebra']);
    $zebra = fingerprint();

    config(['rfa.moved_lines.mode' => 'blocks']);
    $blocks = fingerprint();

    expect($zebra)->toBe($blocks);
});

test('malformed moved-line settings land on the same fingerprint as their normalized form', function () {
    config(['rfa.moved_lines.enabled' => 'off', 'rfa.moved_lines.mode' => 'zebra']);
    $malformedOff = fingerprint();

    config(['rfa.moved_lines.enabled' => false]);
    expect($malformedOff)->toBe(fingerprint());

    config(['rfa.moved_lines.enabled' => true, 'rfa.moved_lines.mode' => 'not-a-mode']);
    $malformedMode = fingerprint();

    config(['rfa.moved_lines.mode' => 'zebra']);
    expect($malformedMode)->toBe(fingerprint());
});

test('the fingerprint reads only the effective values', function () {
    $config = new ReviewConfig(
        diffMaxBytes: 1,
        sourceMaxBytes: 1,
        cacheTtlHours: 1,
        defaultContextLines: 0,
        movedLineDetection: false,
        movedLineMode: 'blocks',
    );

    expect($config->movedLineFingerprint())->toBe('m0');
});

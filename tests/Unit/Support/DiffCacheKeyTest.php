<?php

use App\DTOs\ReviewConfig;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

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
    $off = reviewFingerprint();

    config(['rfa.moved_lines.enabled' => true, 'rfa.moved_lines.mode' => 'zebra']);
    $onZebra = reviewFingerprint();

    config(['rfa.moved_lines.mode' => 'blocks']);
    $onBlocks = reviewFingerprint();

    expect($off)->not->toBe($onZebra)
        ->and($onZebra)->not->toBe($onBlocks);
});

test('the fingerprint ignores the moved-line mode while detection is off', function () {
    config(['rfa.moved_lines.enabled' => false, 'rfa.moved_lines.mode' => 'zebra']);
    $zebra = reviewFingerprint();

    config(['rfa.moved_lines.mode' => 'blocks']);
    $blocks = reviewFingerprint();

    expect($zebra)->toBe($blocks);
});

test('malformed moved-line settings land on the same fingerprint as their normalized form', function () {
    config(['rfa.moved_lines.enabled' => 'off', 'rfa.moved_lines.mode' => 'zebra']);
    $malformedOff = reviewFingerprint();

    config(['rfa.moved_lines.enabled' => false]);
    expect($malformedOff)->toBe(reviewFingerprint());

    config(['rfa.moved_lines.enabled' => true, 'rfa.moved_lines.mode' => 'not-a-mode']);
    $malformedMode = reviewFingerprint();

    config(['rfa.moved_lines.mode' => 'zebra']);
    expect($malformedMode)->toBe(reviewFingerprint());
});

test('the fingerprint varies by the diff size limit', function () {
    config(['rfa.diff_max_bytes' => 512_000]);
    $small = reviewFingerprint();

    config(['rfa.diff_max_bytes' => 2_048_000]);

    expect(reviewFingerprint())->not->toBe($small);
});

test('the fingerprint varies by the default context lines', function () {
    config(['rfa.default_context_lines' => 3]);
    $narrow = reviewFingerprint();

    config(['rfa.default_context_lines' => 8]);

    expect(reviewFingerprint())->not->toBe($narrow);
});

test('the fingerprint ignores settings that do not shape the stored diff', function () {
    config(['rfa.source_max_bytes' => 1_048_576, 'rfa.cache_ttl_hours' => 24]);
    $before = reviewFingerprint();

    config(['rfa.source_max_bytes' => 4_000_000, 'rfa.cache_ttl_hours' => 1]);

    expect(reviewFingerprint())->toBe($before);
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

    expect($config->cacheFingerprint())->toBe('m0|b1|c0');
});

<?php

use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

test('for is deterministic and varies by project, file, and context', function () {
    $a = DiffCacheKey::for(1, 'file-a');
    $b = DiffCacheKey::for(1, 'file-a');
    $c = DiffCacheKey::for(2, 'file-a');
    $d = DiffCacheKey::for(1, 'file-b');
    $e = DiffCacheKey::for(1, 'file-a', 'HEAD..abc');

    expect($a)->toBe($b)
        ->and($a)->not->toBe($c)
        ->and($a)->not->toBe($d)
        ->and($a)->not->toBe($e);
});

test('forget clears every variant of a file diff', function () {
    foreach (DiffCacheKey::VARIANTS as $variant) {
        Cache::put(DiffCacheKey::for(7, 'file-x', 'HEAD..working'.$variant), 'stale', 60);
    }

    DiffCacheKey::forget(7, 'file-x', 'HEAD..working');

    foreach (DiffCacheKey::VARIANTS as $variant) {
        expect(Cache::has(DiffCacheKey::for(7, 'file-x', 'HEAD..working'.$variant)))->toBeFalse();
    }
});

test('VARIANTS includes the base and full-context shapes', function () {
    expect(DiffCacheKey::VARIANTS)->toContain('')->toContain(':full-context');
});

test('for varies by the moved-line detection settings', function () {
    config(['rfa.moved_lines.enabled' => false]);
    $off = DiffCacheKey::for(1, 'file-a');

    config(['rfa.moved_lines.enabled' => true, 'rfa.moved_lines.mode' => 'zebra']);
    $onZebra = DiffCacheKey::for(1, 'file-a');

    config(['rfa.moved_lines.mode' => 'blocks']);
    $onBlocks = DiffCacheKey::for(1, 'file-a');

    expect($off)->not->toBe($onZebra)
        ->and($onZebra)->not->toBe($onBlocks);
});

test('for ignores the moved-line mode while detection is off', function () {
    config(['rfa.moved_lines.enabled' => false, 'rfa.moved_lines.mode' => 'zebra']);
    $zebra = DiffCacheKey::for(1, 'file-a');

    config(['rfa.moved_lines.mode' => 'blocks']);
    $blocks = DiffCacheKey::for(1, 'file-a');

    expect($zebra)->toBe($blocks);
});

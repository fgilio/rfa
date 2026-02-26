<?php

use App\DTOs\ReviewFilePair;
use Carbon\Carbon;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

// -- extractBasename --

test('extracts basename from json review file path', function () {
    $result = ReviewFilePair::extractBasename('.rfa/20250115_143022_comments_AbCdEf12.json');

    expect($result)->toBe('20250115_143022_comments_AbCdEf12');
});

test('extracts basename from md review file path', function () {
    $result = ReviewFilePair::extractBasename('.rfa/20250115_143022_comments_AbCdEf12.md');

    expect($result)->toBe('20250115_143022_comments_AbCdEf12');
});

test('returns null for non-rfa path', function () {
    expect(ReviewFilePair::extractBasename('src/app/Foo.php'))->toBeNull();
});

test('returns null for rfa file without comments pattern', function () {
    expect(ReviewFilePair::extractBasename('.rfa/config.json'))->toBeNull();
});

test('returns null for invalid timestamp format', function () {
    expect(ReviewFilePair::extractBasename('.rfa/abc_def_comments_hash1234.json'))->toBeNull();
});

test('handles nested rfa path', function () {
    $result = ReviewFilePair::extractBasename('some/repo/.rfa/20250115_143022_comments_Ab12Cd34.json');

    expect($result)->toBe('20250115_143022_comments_Ab12Cd34');
});

// -- parseTimestamp --

test('parses timestamp from valid basename', function () {
    $result = ReviewFilePair::parseTimestamp('20250115_143022_comments_AbCdEf12');

    expect($result)->toBeInstanceOf(Carbon::class)
        ->and($result->format('Y-m-d H:i:s'))->toBe('2025-01-15 14:30:22');
});

test('returns null for invalid basename', function () {
    expect(ReviewFilePair::parseTimestamp('not_a_basename'))->toBeNull();
});

// -- toArray --

test('serializes to array with all fields', function () {
    $createdAt = Carbon::parse('2025-01-15 14:30:22');
    $pair = new ReviewFilePair(
        basename: '20250115_143022_comments_AbCdEf12',
        jsonFile: ['id' => 'file-abc', 'path' => '.rfa/20250115_143022_comments_AbCdEf12.json'],
        mdFile: ['id' => 'file-def', 'path' => '.rfa/20250115_143022_comments_AbCdEf12.md'],
        createdAt: $createdAt,
    );

    $array = $pair->toArray();

    expect($array['id'])->toBe('review-'.hash('xxh128', '20250115_143022_comments_AbCdEf12'))
        ->and($array['basename'])->toBe('20250115_143022_comments_AbCdEf12')
        ->and($array['jsonFile'])->toBe(['id' => 'file-abc', 'path' => '.rfa/20250115_143022_comments_AbCdEf12.json'])
        ->and($array['mdFile'])->toBe(['id' => 'file-def', 'path' => '.rfa/20250115_143022_comments_AbCdEf12.md'])
        ->and($array['createdAt'])->toBe($createdAt->toIso8601String())
        ->and($array['createdAtHuman'])->not->toBeNull();
});

test('handles nullable files in toArray', function () {
    $pair = new ReviewFilePair(
        basename: '20250115_143022_comments_AbCdEf12',
        jsonFile: null,
        mdFile: ['id' => 'file-def', 'path' => '.rfa/20250115_143022_comments_AbCdEf12.md'],
        createdAt: Carbon::parse('2025-01-15 14:30:22'),
    );

    $array = $pair->toArray();

    expect($array['jsonFile'])->toBeNull()
        ->and($array['mdFile'])->not->toBeNull();
});

test('handles null createdAt in toArray', function () {
    $pair = new ReviewFilePair(
        basename: '20250115_143022_comments_AbCdEf12',
        jsonFile: null,
        mdFile: null,
        createdAt: null,
    );

    $array = $pair->toArray();

    expect($array['createdAt'])->toBeNull()
        ->and($array['createdAtHuman'])->toBeNull();
});

<?php

use App\DTOs\ReviewFilePair;
use Carbon\Carbon;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

// -- extractBasename --

test('extracts basename from md review file path', function () {
    $result = ReviewFilePair::extractBasename('.rfa/20250115_143022_comments_AbCdEf12.md');

    expect($result)->toBe('20250115_143022_comments_AbCdEf12');
});

test('returns null for json review file path', function () {
    expect(ReviewFilePair::extractBasename('.rfa/20250115_143022_comments_AbCdEf12.json'))->toBeNull();
});

test('returns null for non-rfa path', function () {
    expect(ReviewFilePair::extractBasename('src/app/Foo.php'))->toBeNull();
});

test('returns null for rfa file without comments pattern', function () {
    expect(ReviewFilePair::extractBasename('.rfa/config.md'))->toBeNull();
});

test('returns null for invalid timestamp format', function () {
    expect(ReviewFilePair::extractBasename('.rfa/abc_def_comments_hash1234.md'))->toBeNull();
});

test('handles nested rfa path', function () {
    $result = ReviewFilePair::extractBasename('some/repo/.rfa/20250115_143022_comments_Ab12Cd34.md');

    expect($result)->toBe('20250115_143022_comments_Ab12Cd34');
});

// -- isArtifactPath --

test('isArtifactPath recognizes comment exports and snapshots but not other files', function () {
    expect(ReviewFilePair::isArtifactPath('.rfa/20250115_143022_comments_AbCd1234.md'))->toBeTrue()
        ->and(ReviewFilePair::isArtifactPath('.rfa/20260101_120000_snapshot_AbCd1234.json'))->toBeTrue()
        ->and(ReviewFilePair::isArtifactPath('some/repo/.rfa/20260101_120000_snapshot_ef12AB.json'))->toBeTrue()
        // A source file that merely lives elsewhere is not an artifact.
        ->and(ReviewFilePair::isArtifactPath('src/app/Foo.php'))->toBeFalse()
        // A user's own .rfa/ file without the timestamped artifact naming stays visible.
        ->and(ReviewFilePair::isArtifactPath('.rfa/notes.json'))->toBeFalse()
        // A snapshot must be .json; the comment side must be .md.
        ->and(ReviewFilePair::isArtifactPath('.rfa/20260101_120000_snapshot_AbCd1234.md'))->toBeFalse();
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
        mdFile: ['id' => 'file-def', 'path' => '.rfa/20250115_143022_comments_AbCdEf12.md'],
        createdAt: $createdAt,
    );

    $array = $pair->toArray();

    expect($array['id'])->toBe('review-'.hash('xxh128', '20250115_143022_comments_AbCdEf12'))
        ->and($array['basename'])->toBe('20250115_143022_comments_AbCdEf12')
        ->and($array['mdFile'])->toBe(['id' => 'file-def', 'path' => '.rfa/20250115_143022_comments_AbCdEf12.md'])
        ->and($array['createdAt'])->toBe($createdAt->toIso8601String())
        ->and($array['createdAtHuman'])->not->toBeNull();
});

test('handles null createdAt in toArray', function () {
    $pair = new ReviewFilePair(
        basename: '20250115_143022_comments_AbCdEf12',
        mdFile: ['id' => 'file-def', 'path' => '.rfa/20250115_143022_comments_AbCdEf12.md'],
        createdAt: null,
    );

    $array = $pair->toArray();

    expect($array['createdAt'])->toBeNull()
        ->and($array['createdAtHuman'])->toBeNull();
});

// -- displayName --

test('displayName formats timestamp as friendly date', function () {
    $pair = new ReviewFilePair(
        basename: '20250226_231521_comments_aA06ntL4',
        mdFile: ['id' => 'file-def', 'path' => '.rfa/20250226_231521_comments_aA06ntL4.md'],
        createdAt: Carbon::parse('2025-02-26 23:15:21'),
    );

    expect($pair->toArray()['displayName'])->toBe('Feb 26, 11:15 PM');
});

test('displayName falls back to basename when createdAt is null', function () {
    $pair = new ReviewFilePair(
        basename: '20250226_231521_comments_aA06ntL4',
        mdFile: ['id' => 'file-def', 'path' => '.rfa/20250226_231521_comments_aA06ntL4.md'],
        createdAt: null,
    );

    expect($pair->toArray()['displayName'])->toBe('20250226_231521_comments_aA06ntL4');
});

// -- isValidBasename --

test('isValidBasename accepts canonical pattern', function () {
    expect(ReviewFilePair::isValidBasename('20250115_143022_comments_AbCdEf12'))->toBeTrue();
});

test('isValidBasename rejects path traversal', function () {
    expect(ReviewFilePair::isValidBasename('../../etc/passwd'))->toBeFalse();
});

test('isValidBasename rejects unrelated strings', function () {
    expect(ReviewFilePair::isValidBasename('random_file_name'))->toBeFalse();
});

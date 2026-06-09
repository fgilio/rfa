<?php

use App\DTOs\DiffLine;
use App\DTOs\FileDiff;
use App\DTOs\FileSourceSpec;
use App\DTOs\Hunk;
use App\Enums\LineType;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('toArray returns all expected keys', function () {
    $lines = [
        new DiffLine(LineType::Context, $this->faker->sentence(), 1, 1),
        new DiffLine(LineType::Add, $this->faker->sentence(), null, 2),
    ];

    $hunk = new Hunk('fn()', 1, 1, 1, 2, $lines);
    $path = $this->faker->word().'.php';

    $fileDiff = new FileDiff(
        path: $path,
        status: 'modified',
        oldPath: null,
        hunks: [$hunk],
        additions: 1,
        deletions: 0,
    );

    $result = $fileDiff->toArray();

    expect($result)->toHaveKeys(['path', 'status', 'oldPath', 'hunks', 'additions', 'deletions', 'isBinary'])
        ->and($result['path'])->toBe($path)
        ->and($result['status'])->toBe('modified')
        ->and($result['oldPath'])->toBeNull()
        ->and($result['hunks'])->toHaveCount(1)
        ->and($result['hunks'][0]['header'])->toBe('fn()')
        ->and($result['hunks'][0]['lines'])->toHaveCount(2)
        ->and($result['hunks'][0]['lines'][0])->toBe($lines[0]->toArray())
        ->and($result['additions'])->toBe(1)
        ->and($result['deletions'])->toBe(0)
        ->and($result['isBinary'])->toBeFalse()
        ->and($result)->not->toHaveKeys(['oldSource', 'newSource']);
});

test('toArray handles empty hunks', function () {
    $fileDiff = new FileDiff('f.php', 'added', null, [], 0, 0);

    $result = $fileDiff->toArray();

    expect($result['hunks'])->toBe([])
        ->and($result['path'])->toBe('f.php')
        ->and($result['status'])->toBe('added');
});

test('withHunks returns new instance with replaced hunks', function () {
    $oldSource = FileSourceSpec::git('HEAD', 'f.php');
    $newSource = FileSourceSpec::working('f.php');
    $original = new FileDiff('f.php', 'modified', null, [], 1, 0, oldSource: $oldSource, newSource: $newSource);
    $newHunks = [new Hunk('fn()', 1, 1, 1, 1, [new DiffLine(LineType::Add, 'new', null, 1)])];

    $updated = $original->withHunks($newHunks);

    expect($updated)->not->toBe($original)
        ->and($updated->hunks)->toBe($newHunks)
        ->and($updated->path)->toBe('f.php')
        ->and($updated->additions)->toBe(1)
        ->and($updated->oldSource)->toBe($oldSource)
        ->and($updated->newSource)->toBe($newSource);
});

test('withSourceSpecs returns new instance with source metadata', function () {
    $oldSource = FileSourceSpec::git('HEAD', 'f.php');
    $newSource = FileSourceSpec::working('f.php');
    $original = new FileDiff('f.php', 'modified', null, [], 1, 0);

    $updated = $original->withSourceSpecs($oldSource, $newSource);

    expect($updated)->not->toBe($original)
        ->and($updated->oldSource)->toBe($oldSource)
        ->and($updated->newSource)->toBe($newSource)
        ->and($updated->toArray())->not->toHaveKeys(['oldSource', 'newSource']);
});

test('emptyArray returns tooLarge array structure', function () {
    $result = FileDiff::emptyArray('big.php', 'modified', tooLarge: true);

    expect($result)->toBe([
        'path' => 'big.php',
        'status' => 'modified',
        'oldPath' => null,
        'hunks' => [],
        'additions' => 0,
        'deletions' => 0,
        'isBinary' => false,
        'isSymlink' => false,
        'symlinkTarget' => null,
        'tooLarge' => true,
        'skipReason' => null,
        'syntaxHighlighter' => 'none',
    ]);
});

test('emptyArray returns non-tooLarge array structure', function () {
    $result = FileDiff::emptyArray('empty.php', 'added', tooLarge: false);

    expect($result['tooLarge'])->toBeFalse()
        ->and($result['skipReason'])->toBeNull()
        ->and($result['status'])->toBe('added');
});

test('emptyArray preserves skipped reason', function () {
    $result = FileDiff::emptyArray('big.php', 'modified', tooLarge: true, skipReason: 'too-large');

    expect($result['tooLarge'])->toBeTrue()
        ->and($result['skipReason'])->toBe('too-large');
});

test('emptyArray does not serialize source specs', function () {
    $oldSource = FileSourceSpec::git('HEAD', 'big.php');
    $newSource = FileSourceSpec::working('big.php');

    $result = (new FileDiff('big.php', 'modified', null, [], 0, 0))
        ->withSourceSpecs($oldSource, $newSource)
        ->toArray();

    expect($result)->not->toHaveKeys(['oldSource', 'newSource']);
});

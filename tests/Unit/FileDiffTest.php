<?php

use App\DTOs\DiffLine;
use App\DTOs\FileDiff;
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
        ->and($result['isBinary'])->toBeFalse();
});

test('toArray handles empty hunks', function () {
    $fileDiff = new FileDiff('f.php', 'added', null, [], 0, 0);

    $result = $fileDiff->toArray();

    expect($result['hunks'])->toBe([])
        ->and($result['path'])->toBe('f.php')
        ->and($result['status'])->toBe('added');
});

test('withHunks returns new instance with replaced hunks', function () {
    $original = new FileDiff('f.php', 'modified', null, [], 1, 0);
    $newHunks = [new Hunk('fn()', 1, 1, 1, 1, [new DiffLine(LineType::Add, 'new', null, 1)])];

    $updated = $original->withHunks($newHunks);

    expect($updated)->not->toBe($original)
        ->and($updated->hunks)->toBe($newHunks)
        ->and($updated->path)->toBe('f.php')
        ->and($updated->additions)->toBe(1);
});

test('emptyArray returns the no-hunks payload shape', function () {
    expect(FileDiff::emptyArray('big.php', 'modified'))->toBe([
        'path' => 'big.php',
        'status' => 'modified',
        'oldPath' => null,
        'hunks' => [],
        'additions' => 0,
        'deletions' => 0,
        'isBinary' => false,
        'isSymlink' => false,
        'symlinkTarget' => null,
    ]);
});

test('emptyArray carries the given status', function () {
    expect(FileDiff::emptyArray('empty.php', 'added')['status'])->toBe('added');
});

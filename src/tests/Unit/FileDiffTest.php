<?php

use App\DTOs\DiffLine;
use App\DTOs\FileDiff;
use App\DTOs\Hunk;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('toArray returns all expected keys', function () {
    $lines = [
        new DiffLine('context', $this->faker->sentence(), 1, 1),
        new DiffLine('add', $this->faker->sentence(), null, 2),
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

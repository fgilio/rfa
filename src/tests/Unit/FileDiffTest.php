<?php

use App\DTOs\DiffLine;
use App\DTOs\FileDiff;
use App\DTOs\Hunk;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('toViewArray returns hunks and tooLarge false', function () {
    $lines = [
        new DiffLine('context', $this->faker->sentence(), 1, 1),
        new DiffLine('add', $this->faker->sentence(), null, 2),
    ];

    $hunk = new Hunk('fn()', 1, 1, 1, 2, $lines);

    $fileDiff = new FileDiff(
        path: $this->faker->word().'.php',
        status: 'modified',
        oldPath: null,
        hunks: [$hunk],
        additions: 1,
        deletions: 0,
    );

    $view = $fileDiff->toViewArray();

    expect($view)->toHaveKeys(['hunks', 'tooLarge']);
    expect($view['tooLarge'])->toBeFalse();
    expect($view['hunks'])->toHaveCount(1);
    expect($view['hunks'][0]['header'])->toBe('fn()');
    expect($view['hunks'][0]['lines'])->toHaveCount(2);
    expect($view['hunks'][0]['lines'][0])->toBe($lines[0]->toArray());
});

test('toViewArray handles empty hunks', function () {
    $fileDiff = new FileDiff('f.php', 'added', null, [], 0, 0);

    expect($fileDiff->toViewArray())->toBe([
        'hunks' => [],
        'tooLarge' => false,
    ]);
});

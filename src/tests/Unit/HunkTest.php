<?php

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('toArray serializes header, starts, and lines', function () {
    $header = 'function '.$this->faker->word().'()';
    $oldStart = $this->faker->numberBetween(1, 100);
    $newStart = $this->faker->numberBetween(1, 100);

    $lines = [
        new DiffLine('context', $this->faker->sentence(), $oldStart, $newStart),
        new DiffLine('remove', $this->faker->sentence(), $oldStart + 1, null),
        new DiffLine('add', $this->faker->sentence(), null, $newStart + 1),
    ];

    $hunk = new Hunk($header, $oldStart, 2, $newStart, 2, $lines);

    $array = $hunk->toArray();

    expect($array['header'])->toBe($header);
    expect($array['oldStart'])->toBe($oldStart);
    expect($array['newStart'])->toBe($newStart);
    expect($array['lines'])->toHaveCount(3);
    expect($array['lines'][0])->toBe($lines[0]->toArray());
    expect($array['lines'][1]['type'])->toBe('remove');
    expect($array['lines'][2]['type'])->toBe('add');
});

test('toArray handles empty lines', function () {
    $hunk = new Hunk('', 1, 0, 1, 0, []);

    expect($hunk->toArray()['lines'])->toBeEmpty();
});

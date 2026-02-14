<?php

use App\DTOs\DiffLine;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('toArray returns all properties', function () {
    $type = $this->faker->randomElement(['context', 'add', 'remove']);
    $content = $this->faker->sentence();
    $oldLineNum = $this->faker->numberBetween(1, 500);
    $newLineNum = $this->faker->numberBetween(1, 500);

    $line = new DiffLine($type, $content, $oldLineNum, $newLineNum);

    expect($line->toArray())->toBe([
        'type' => $type,
        'content' => $content,
        'oldLineNum' => $oldLineNum,
        'newLineNum' => $newLineNum,
    ]);
});

test('toArray handles null line numbers', function () {
    $line = new DiffLine('add', $this->faker->sentence(), null, $this->faker->numberBetween(1, 100));

    $array = $line->toArray();

    expect($array['oldLineNum'])->toBeNull();
    expect($array['newLineNum'])->toBeInt();
});

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

test('toArray includes highlightedContent when set', function () {
    $highlighted = '<span style="color:#000">code</span>';

    $line = new DiffLine('add', 'code', null, 1, $highlighted);

    $array = $line->toArray();

    expect($array)->toHaveKey('highlightedContent')
        ->and($array['highlightedContent'])->toBe($highlighted);
});

test('toArray omits highlightedContent when null', function () {
    $line = new DiffLine('context', 'code', 1, 1);

    expect($line->toArray())->not->toHaveKey('highlightedContent');
});

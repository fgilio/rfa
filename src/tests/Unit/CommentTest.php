<?php

use App\DTOs\Comment;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('toArray round-trips all properties', function () {
    $id = $this->faker->uuid();
    $file = $this->faker->filePath();
    $side = $this->faker->randomElement(['left', 'right', 'file']);
    $startLine = $this->faker->numberBetween(1, 500);
    $endLine = $this->faker->numberBetween($startLine, $startLine + 50);
    $body = $this->faker->paragraph();

    $comment = new Comment($id, $file, $side, $startLine, $endLine, $body);
    $array = $comment->toArray();

    expect($array)->toBe([
        'id' => $id,
        'file' => $file,
        'side' => $side,
        'start_line' => $startLine,
        'end_line' => $endLine,
        'body' => $body,
    ]);
});

test('toArray handles null lines', function () {
    $comment = new Comment(
        $this->faker->uuid(),
        $this->faker->filePath(),
        'file',
        null,
        null,
        $this->faker->sentence(),
    );

    $array = $comment->toArray();

    expect($array['start_line'])->toBeNull();
    expect($array['end_line'])->toBeNull();
});

test('toArray preserves special characters in body', function () {
    $body = "Line with <html> & \"quotes\" and 'apostrophes' \n\ttabs too";

    $comment = new Comment($this->faker->uuid(), 'file.php', 'right', 1, 1, $body);

    expect($comment->toArray()['body'])->toBe($body);
});

test('properties are readonly', function () {
    $comment = new Comment($this->faker->uuid(), 'f.php', 'right', 1, 1, 'body');

    $ref = new ReflectionClass($comment);
    foreach ($ref->getProperties() as $prop) {
        expect($prop->isReadOnly())->toBeTrue("Property {$prop->getName()} should be readonly");
    }
});

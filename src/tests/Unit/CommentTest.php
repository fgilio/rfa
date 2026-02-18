<?php

use App\DTOs\Comment;
use App\Enums\DiffSide;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('toArray returns camelCase keys for internal use', function () {
    $id = $this->faker->uuid();
    $fileId = 'file-'.$this->faker->uuid();
    $file = $this->faker->filePath();
    $side = $this->faker->randomElement(DiffSide::cases());
    $startLine = $this->faker->numberBetween(1, 500);
    $endLine = $this->faker->numberBetween($startLine, $startLine + 50);
    $body = $this->faker->paragraph();

    $comment = new Comment($id, $fileId, $file, $side, $startLine, $endLine, $body);
    $array = $comment->toArray();

    expect($array)->toBe([
        'id' => $id,
        'fileId' => $fileId,
        'file' => $file,
        'side' => $side->value,
        'startLine' => $startLine,
        'endLine' => $endLine,
        'body' => $body,
    ]);
});

test('toExportArray returns snake_case keys without fileId', function () {
    $id = $this->faker->uuid();
    $fileId = 'file-'.$this->faker->uuid();
    $file = $this->faker->filePath();
    $startLine = $this->faker->numberBetween(1, 500);
    $endLine = $this->faker->numberBetween($startLine, $startLine + 50);
    $body = $this->faker->paragraph();

    $comment = new Comment($id, $fileId, $file, DiffSide::Right, $startLine, $endLine, $body);
    $array = $comment->toExportArray();

    expect($array)->toBe([
        'id' => $id,
        'file' => $file,
        'side' => 'right',
        'start_line' => $startLine,
        'end_line' => $endLine,
        'body' => $body,
    ]);
    expect($array)->not->toHaveKey('fileId');
});

test('toArray handles null lines', function () {
    $comment = new Comment(
        $this->faker->uuid(),
        'file-abc',
        $this->faker->filePath(),
        DiffSide::File,
        null,
        null,
        $this->faker->sentence(),
    );

    $array = $comment->toArray();

    expect($array['startLine'])->toBeNull();
    expect($array['endLine'])->toBeNull();
});

test('toArray preserves special characters in body', function () {
    $body = "Line with <html> & \"quotes\" and 'apostrophes' \n\ttabs too";

    $comment = new Comment($this->faker->uuid(), 'file-abc', 'file.php', DiffSide::Right, 1, 1, $body);

    expect($comment->toArray()['body'])->toBe($body);
});

test('fromArray constructs from camelCase array', function () {
    $data = [
        'id' => $this->faker->uuid(),
        'fileId' => 'file-'.$this->faker->uuid(),
        'file' => 'src/app.php',
        'side' => 'left',
        'startLine' => 10,
        'endLine' => 15,
        'body' => 'test body',
    ];

    $comment = Comment::fromArray($data);

    expect($comment->id)->toBe($data['id'])
        ->and($comment->fileId)->toBe($data['fileId'])
        ->and($comment->file)->toBe('src/app.php')
        ->and($comment->side)->toBe(DiffSide::Left)
        ->and($comment->startLine)->toBe(10)
        ->and($comment->endLine)->toBe(15)
        ->and($comment->body)->toBe('test body');
});

test('side property is DiffSide enum', function () {
    $comment = new Comment($this->faker->uuid(), 'file-abc', 'f.php', DiffSide::Right, 1, 1, 'body');

    expect($comment->side)->toBeInstanceOf(DiffSide::class)
        ->and($comment->side)->toBe(DiffSide::Right);
});

test('properties are readonly', function () {
    $comment = new Comment($this->faker->uuid(), 'file-abc', 'f.php', DiffSide::Right, 1, 1, 'body');

    $ref = new ReflectionClass($comment);
    foreach ($ref->getProperties() as $prop) {
        expect($prop->isReadOnly())->toBeTrue("Property {$prop->getName()} should be readonly");
    }
});

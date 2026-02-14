<?php

use App\Actions\AddCommentAction;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->action = new AddCommentAction;
    $this->files = [
        ['id' => 'file-abc', 'path' => 'src/hello.php'],
    ];
});

test('returns comment array on valid input', function () {
    $body = $this->faker->sentence();

    $result = $this->action->handle($this->files, 'file-abc', 'right', 10, 10, $body);

    expect($result)->not->toBeNull();
    expect($result['id'])->toStartWith('c-');
    expect($result['fileId'])->toBe('file-abc');
    expect($result['file'])->toBe('src/hello.php');
    expect($result['side'])->toBe('right');
    expect($result['startLine'])->toBe(10);
    expect($result['endLine'])->toBe(10);
    expect($result['body'])->toBe($body);
});

test('returns null for empty body', function () {
    expect($this->action->handle($this->files, 'file-abc', 'right', 1, 1, ''))->toBeNull();
    expect($this->action->handle($this->files, 'file-abc', 'right', 1, 1, '   '))->toBeNull();
});

test('returns null for invalid file id', function () {
    $result = $this->action->handle($this->files, 'file-nonexistent', 'right', 1, 1, 'body');

    expect($result)->toBeNull();
});

test('returns null for invalid side', function () {
    $result = $this->action->handle($this->files, 'file-abc', 'invalid', 1, 1, 'body');

    expect($result)->toBeNull();
});

test('accepts file-level comments with null lines', function () {
    $result = $this->action->handle($this->files, 'file-abc', 'file', null, null, 'general note');

    expect($result)->not->toBeNull();
    expect($result['startLine'])->toBeNull();
    expect($result['endLine'])->toBeNull();
});

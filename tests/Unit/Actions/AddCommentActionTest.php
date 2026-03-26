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

// -- impossible-state guards --

test('returns null for file-level comment with line numbers', function () {
    expect($this->action->handle($this->files, 'file-abc', 'file', 1, 5, 'body'))->toBeNull();
    expect($this->action->handle($this->files, 'file-abc', 'file', 1, null, 'body'))->toBeNull();
    expect($this->action->handle($this->files, 'file-abc', 'file', null, 5, 'body'))->toBeNull();
});

test('returns null for line comment with null startLine', function () {
    expect($this->action->handle($this->files, 'file-abc', 'right', null, 5, 'body'))->toBeNull();
    expect($this->action->handle($this->files, 'file-abc', 'left', null, null, 'body'))->toBeNull();
});

test('returns null when startLine exceeds endLine', function () {
    expect($this->action->handle($this->files, 'file-abc', 'right', 10, 5, 'body'))->toBeNull();
});

<?php

use App\Actions\DeleteCommentAction;
use App\Models\Comment;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->action = new DeleteCommentAction;
});

test('removes comment by id', function () {
    $comments = [
        ['id' => 'c-aaa', 'body' => 'first'],
        ['id' => 'c-bbb', 'body' => 'second'],
    ];

    $result = $this->action->handle($comments, 'c-aaa');

    expect($result)->toHaveCount(1);
    expect($result[0]['id'])->toBe('c-bbb');
});

test('deletes the comment row from the comments table', function () {
    Comment::create([
        'id' => 'c-aaa',
        'repo_path' => '/tmp/repo',
        'origin_ref' => 'working',
        'file_path' => 'f.php',
        'side' => 'right',
        'body' => 'first',
    ]);

    $this->action->handle([['id' => 'c-aaa']], 'c-aaa');

    expect(Comment::find('c-aaa'))->toBeNull();
});

test('returns null for invalid prefix', function () {
    $comments = [['id' => 'c-aaa', 'body' => 'first']];

    expect($this->action->handle($comments, 'invalid-id'))->toBeNull();
});

test('returns empty array when last comment deleted', function () {
    $comments = [['id' => 'c-only', 'body' => 'only']];

    $result = $this->action->handle($comments, 'c-only');

    expect($result)->toBeEmpty();
});

test('reindexes array after deletion', function () {
    $comments = [
        ['id' => 'c-1', 'body' => 'a'],
        ['id' => 'c-2', 'body' => 'b'],
        ['id' => 'c-3', 'body' => 'c'],
    ];

    $result = $this->action->handle($comments, 'c-2');

    expect(array_keys($result))->toBe([0, 1]);
});

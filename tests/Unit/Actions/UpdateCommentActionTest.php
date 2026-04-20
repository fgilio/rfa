<?php

use App\Actions\UpdateCommentAction;
use App\Models\Comment;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->action = new UpdateCommentAction;

    Comment::create([
        'id' => 'c-existing',
        'repo_path' => '/tmp/repo',
        'origin_ref' => 'working',
        'file_path' => 'src/f.php',
        'side' => 'right',
        'start_line' => 5,
        'body' => 'original body',
        'is_draft' => false,
    ]);
});

test('updates the body of an existing comment', function () {
    $ok = $this->action->handle('c-existing', 'rewritten body', false);

    expect($ok)->toBeTrue();
    expect(Comment::find('c-existing')->body)->toBe('rewritten body');
});

test('updates the draft flag', function () {
    $this->action->handle('c-existing', 'still same body', true);

    expect(Comment::find('c-existing')->is_draft)->toBeTrue();
});

test('returns false for ids that do not match the c- prefix', function () {
    expect($this->action->handle('garbage', 'body', false))->toBeFalse();
    expect($this->action->handle('', 'body', false))->toBeFalse();
});

test('returns false when no comment matches the id', function () {
    expect($this->action->handle('c-missing', 'body', false))->toBeFalse();
});

test('leaves other comments untouched', function () {
    Comment::create([
        'id' => 'c-other',
        'repo_path' => '/tmp/repo',
        'origin_ref' => 'working',
        'file_path' => 'src/g.php',
        'side' => 'right',
        'body' => 'other',
    ]);

    $this->action->handle('c-existing', 'changed', false);

    expect(Comment::find('c-other')->body)->toBe('other');
});

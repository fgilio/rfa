<?php

use App\Enums\CommentAuthorType;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('replies are ordered deterministically and cascade with their root comment', function () {
    DB::statement('PRAGMA foreign_keys = ON');

    $project = Project::factory()->create(['path' => '/tmp/replies']);
    $comment = Comment::factory()->for($project)->create([
        'repo_path' => '/tmp/replies',
    ]);

    CommentReply::factory()->for($comment)->create([
        'id' => 'r-later',
        'created_at' => '2026-07-27 12:00:00',
    ]);
    CommentReply::factory()->for($comment)->create([
        'id' => 'r-b',
        'created_at' => '2026-07-27 11:00:00',
    ]);
    CommentReply::factory()->for($comment)->create([
        'id' => 'r-a',
        'created_at' => '2026-07-27 11:00:00',
    ]);

    expect($comment->replies()->pluck('id')->all())->toBe(['r-a', 'r-b', 'r-later']);

    $comment->delete();

    expect(CommentReply::query()->count())->toBe(0)
        ->and(DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
});

test('reply author type is cast to its enum', function () {
    $reply = CommentReply::factory()->agent()->create();

    expect($reply->author_type)->toBe(CommentAuthorType::Agent);
});

test('migration uses a string foreign key and chronological composite index', function () {
    $columns = collect(Schema::getColumns('comment_replies'))->keyBy('name');
    $indexes = Schema::getIndexes('comment_replies');

    expect($columns['comment_id']['type_name'])->toBe('varchar')
        ->and(collect($indexes)->contains(
            fn (array $index): bool => $index['columns'] === ['comment_id', 'created_at'],
        ))->toBeTrue();
});

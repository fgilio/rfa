<?php

use App\Actions\CreateCommentThreadSnapshotsAction;
use App\Actions\RestoreCommentThreadsAction;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('snapshots database-authoritative roots and restores the complete thread', function () {
    $project = Project::factory()->create(['path' => '/tmp/restore']);
    $comment = Comment::factory()->for($project)->create([
        'id' => 'c-restore',
        'repo_path' => '/tmp/restore',
        'file_path' => 'app/Foo.php',
        'body' => 'Stored root',
        'created_at' => '2026-07-27 09:00:00',
        'updated_at' => '2026-07-27 09:01:00',
    ]);
    CommentReply::factory()->for($comment)->create([
        'id' => 'r-restore',
        'body' => 'Stored reply',
        'created_at' => '2026-07-27 09:02:00',
        'updated_at' => '2026-07-27 09:03:00',
    ]);

    $snapshots = app(CreateCommentThreadSnapshotsAction::class)->handle(
        '/tmp/restore',
        $project->id,
        [[
            'id' => 'c-restore',
            'fileId' => 'f-1',
            'body' => 'Stale view body',
        ]],
    );

    expect($snapshots[0]['version'])->toBe(1)
        ->and($snapshots[0]['comment']['body'])->toBe('Stored root')
        ->and($snapshots[0]['comment']['fileId'])->toBe('f-1')
        ->and($snapshots[0]['replies'][0]['body'])->toBe('Stored reply');

    $comment->delete();

    $restored = app(RestoreCommentThreadsAction::class)->handle(
        '/tmp/restore',
        $project->id,
        $snapshots,
    );

    expect($restored)->toHaveCount(1)
        ->and($restored[0]['body'])->toBe('Stored root')
        ->and($restored[0]['replies'][0])->toMatchArray([
            'id' => 'r-restore',
            'body' => 'Stored reply',
        ])
        ->and(Comment::query()->find('c-restore')->created_at->toDateTimeString())->toBe('2026-07-27 09:00:00')
        ->and(CommentReply::query()->find('r-restore')->created_at->toDateTimeString())->toBe('2026-07-27 09:02:00');
});

test('restores a legacy raw root snapshot without replies', function () {
    $project = Project::factory()->create(['path' => '/tmp/legacy']);

    $restored = app(RestoreCommentThreadsAction::class)->handle(
        '/tmp/legacy',
        $project->id,
        [[
            'id' => 'c-legacy',
            'file' => 'legacy.php',
            'side' => 'right',
            'body' => 'Legacy root',
        ]],
    );

    expect($restored[0]['id'])->toBe('c-legacy')
        ->and($restored[0]['replies'])->toBe([]);
});

test('rolls back every restored thread when any snapshot is out of scope', function () {
    $project = Project::factory()->create(['path' => '/tmp/atomic']);
    $otherProject = Project::factory()->create(['path' => '/tmp/foreign']);
    Comment::factory()->for($otherProject)->create([
        'id' => 'c-collision',
        'repo_path' => '/tmp/foreign',
    ]);

    try {
        app(RestoreCommentThreadsAction::class)->handle(
            '/tmp/atomic',
            $project->id,
            [
                [
                    'id' => 'c-first',
                    'file' => 'first.php',
                    'side' => 'right',
                    'body' => 'Would restore first',
                ],
                [
                    'id' => 'c-collision',
                    'file' => 'collision.php',
                    'side' => 'right',
                    'body' => 'Wrong scope',
                ],
            ],
        );
    } catch (ModelNotFoundException) {
        // Expected: the second snapshot rejects the whole transaction.
    }

    expect(Comment::query()->find('c-first'))->toBeNull();
});

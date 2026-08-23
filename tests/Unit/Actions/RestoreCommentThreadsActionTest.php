<?php

use App\Actions\CreateCommentThreadSnapshotsAction;
use App\Actions\RestoreCommentThreadsAction;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    expect(fn () => app(RestoreCommentThreadsAction::class)->handle(
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
    ))->toThrow(ModelNotFoundException::class);

    expect(Comment::query()->find('c-first'))->toBeNull();
});

test('restores complete threads with a constant number of queries', function () {
    $project = Project::factory()->create(['path' => '/tmp/bulk']);
    $snapshots = collect(range(1, 5))
        ->map(fn (int $comment): array => [
            'id' => "c-bulk-{$comment}",
            'file' => "app/File{$comment}.php",
            'side' => 'right',
            'body' => "Comment {$comment}",
            'replies' => collect(range(1, 3))
                ->map(fn (int $reply): array => [
                    'id' => "r-bulk-{$comment}-{$reply}",
                    'commentId' => "c-bulk-{$comment}",
                    'authorType' => 'human',
                    'authorKey' => 'local-user',
                    'authorLabel' => 'Franco',
                    'body' => "Reply {$reply}",
                    'createdAt' => "2026-07-27T09:0{$reply}:00+00:00",
                    'updatedAt' => "2026-07-27T09:0{$reply}:30+00:00",
                ])
                ->all(),
        ])
        ->all();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $restored = app(RestoreCommentThreadsAction::class)->handle(
        '/tmp/bulk',
        $project->id,
        $snapshots,
    );

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($restored)->toHaveCount(5)
        ->and($restored[4]['replies'])->toHaveCount(3)
        ->and(Comment::query()->count())->toBe(5)
        ->and(CommentReply::query()->count())->toBe(15)
        ->and($queryCount)->toBeLessThanOrEqual(6);
});

test('rejects reply IDs already owned by another thread without restoring roots', function () {
    $project = Project::factory()->create(['path' => '/tmp/reply-collision']);
    $existingComment = Comment::factory()->for($project)->create([
        'id' => 'c-existing',
        'repo_path' => '/tmp/reply-collision',
    ]);
    CommentReply::factory()->for($existingComment)->create(['id' => 'r-collision']);

    expect(fn () => app(RestoreCommentThreadsAction::class)->handle(
        '/tmp/reply-collision',
        $project->id,
        [[
            'id' => 'c-target',
            'file' => 'target.php',
            'side' => 'right',
            'body' => 'Target',
            'replies' => [[
                'id' => 'r-collision',
                'commentId' => 'c-target',
                'authorType' => 'human',
                'authorKey' => 'local-user',
                'body' => 'Collision',
            ]],
        ]],
    ))->toThrow(ModelNotFoundException::class);

    expect(Comment::query()->find('c-target'))->toBeNull()
        ->and(CommentReply::query()->find('r-collision')->comment_id)->toBe('c-existing');
});

test('rejects replies assigned to a different snapshot thread', function () {
    $project = Project::factory()->create(['path' => '/tmp/mismatched-reply']);

    expect(fn () => app(RestoreCommentThreadsAction::class)->handle(
        '/tmp/mismatched-reply',
        $project->id,
        [[
            'id' => 'c-target',
            'file' => 'target.php',
            'side' => 'right',
            'body' => 'Target',
            'replies' => [[
                'id' => 'r-mismatch',
                'commentId' => 'c-other',
                'authorType' => 'human',
                'authorKey' => 'local-user',
                'body' => 'Wrong parent',
            ]],
        ]],
    ))->toThrow(InvalidArgumentException::class, 'does not belong to comment c-target');

    expect(Comment::query()->find('c-target'))->toBeNull();
});

test('rejects duplicate root and reply IDs before restoring snapshots', function (array $snapshots, string $message) {
    $project = Project::factory()->create(['path' => '/tmp/duplicate-snapshots']);

    expect(fn () => app(RestoreCommentThreadsAction::class)->handle(
        '/tmp/duplicate-snapshots',
        $project->id,
        $snapshots,
    ))->toThrow(InvalidArgumentException::class, $message);

    expect(Comment::query()->count())->toBe(0);
})->with([
    'duplicate roots' => [
        [
            ['id' => 'c-duplicate', 'file' => 'one.php', 'side' => 'right', 'body' => 'One'],
            ['id' => 'c-duplicate', 'file' => 'two.php', 'side' => 'right', 'body' => 'Two'],
        ],
        'Comment thread snapshot IDs must be unique.',
    ],
    'duplicate replies' => [
        [
            [
                'id' => 'c-one',
                'file' => 'one.php',
                'side' => 'right',
                'body' => 'One',
                'replies' => [[
                    'id' => 'r-duplicate',
                    'commentId' => 'c-one',
                    'authorType' => 'human',
                    'authorKey' => 'local-user',
                    'body' => 'One',
                ]],
            ],
            [
                'id' => 'c-two',
                'file' => 'two.php',
                'side' => 'right',
                'body' => 'Two',
                'replies' => [[
                    'id' => 'r-duplicate',
                    'commentId' => 'c-two',
                    'authorType' => 'human',
                    'authorKey' => 'local-user',
                    'body' => 'Two',
                ]],
            ],
        ],
        'Comment reply snapshot IDs must be unique.',
    ],
]);

test('a full round trip preserves anchors, sides, timestamps, and reply order', function () {
    $project = Project::factory()->create(['path' => '/tmp/roundtrip']);
    $comment = Comment::factory()->for($project)->create([
        'id' => 'c-roundtrip',
        'repo_path' => '/tmp/roundtrip',
        'origin_ref' => Comment::ORIGIN_CONTEXT,
        'file_path' => 'app/Foo.php',
        'side' => 'left',
        'start_line' => 12,
        'end_line' => 14,
        'file_content_hash' => 'abc123',
        'line_snippet' => '$x = 1;',
        'is_draft' => true,
        'created_at' => '2026-07-27 09:00:00',
        'updated_at' => '2026-07-27 09:01:00',
    ]);
    foreach (['r-first' => 'First', 'r-second' => 'Second'] as $id => $body) {
        CommentReply::factory()->for($comment)->create(['id' => $id, 'body' => $body]);
    }

    $snapshots = app(CreateCommentThreadSnapshotsAction::class)->handle(
        '/tmp/roundtrip',
        $project->id,
        [[
            'id' => 'c-roundtrip',
            'fileId' => 'f-1',
            // The resolver had moved this one to the right for the diff on
            // screen; the snapshot must still record where it is stored.
            'side' => 'right',
            'originalSide' => 'left',
            'anchorStatus' => 'unplaced',
        ]],
    );

    $comment->delete();

    $restored = app(RestoreCommentThreadsAction::class)->handle('/tmp/roundtrip', $project->id, $snapshots);
    $row = Comment::query()->findOrFail('c-roundtrip');

    // The row goes back the way it was stored, not the way it was displayed.
    expect($row->side)->toBe('left')
        ->and($row->origin_ref)->toBe(Comment::ORIGIN_CONTEXT)
        ->and($row->start_line)->toBe(12)
        ->and($row->end_line)->toBe(14)
        ->and($row->file_content_hash)->toBe('abc123')
        ->and($row->line_snippet)->toBe('$x = 1;')
        ->and((bool) $row->is_draft)->toBeTrue()
        ->and($row->created_at->toDateTimeString())->toBe('2026-07-27 09:00:00')
        ->and($row->updated_at->toDateTimeString())->toBe('2026-07-27 09:01:00');

    // The view state comes back off the snapshot, which is database-authoritative
    // for the side: undo must not write back the side the resolver happened to be
    // displaying, and the page re-resolves the anchor on its next load anyway.
    expect($restored[0])->toMatchArray([
        'fileId' => 'f-1',
        'side' => 'left',
        'originalSide' => 'left',
        'anchorStatus' => 'unplaced',
        'originRef' => Comment::ORIGIN_CONTEXT,
    ])
        ->and(array_column($restored[0]['replies'], 'id'))->toBe(['r-first', 'r-second'])
        ->and($restored[0]['createdAt'])->toBe($snapshots[0]['comment']['createdAt']);
});

test('an unplaced anchor round trips as unplaced', function () {
    $project = Project::factory()->create(['path' => '/tmp/unplaced']);
    Comment::factory()->for($project)->create([
        'id' => 'c-unplaced',
        'repo_path' => '/tmp/unplaced',
        'file_path' => 'gone.php',
    ]);

    $snapshots = app(CreateCommentThreadSnapshotsAction::class)->handle(
        '/tmp/unplaced',
        $project->id,
        [['id' => 'c-unplaced', 'fileId' => 'f-gone', 'anchorStatus' => 'unplaced']],
    );

    Comment::query()->whereKey('c-unplaced')->delete();

    $restored = app(RestoreCommentThreadsAction::class)->handle('/tmp/unplaced', $project->id, $snapshots);

    expect($snapshots[0]['comment']['anchorStatus'])->toBe('unplaced')
        ->and($restored[0]['anchorStatus'])->toBe('unplaced');
});

test('a snapshot with no origin ref restores onto the caller surface', function () {
    $project = Project::factory()->create(['path' => '/tmp/surface']);

    app(RestoreCommentThreadsAction::class)->handle(
        '/tmp/surface',
        $project->id,
        [['id' => 'c-surface', 'file' => 'CLAUDE.md', 'side' => 'right', 'body' => 'Root']],
        Comment::ORIGIN_CONTEXT,
    );

    expect(Comment::query()->findOrFail('c-surface')->origin_ref)->toBe(Comment::ORIGIN_CONTEXT);
});

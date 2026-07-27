<?php

use App\Actions\DeleteCommentThreadsAction;
use App\Enums\CommentSurface;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['path' => '/tmp/thread-deletion']);
    $this->action = app(DeleteCommentThreadsAction::class);
});

test('snapshots and deletes complete threads in one operation', function () {
    $deleted = Comment::factory()->for($this->project)->create([
        'id' => 'c-delete',
        'repo_path' => '/tmp/thread-deletion',
        'origin_ref' => Comment::ORIGIN_CONTEXT,
        'file_path' => 'AGENTS.md',
    ]);
    $kept = Comment::factory()->for($this->project)->create([
        'id' => 'c-keep',
        'repo_path' => '/tmp/thread-deletion',
        'origin_ref' => Comment::ORIGIN_CONTEXT,
    ]);
    $reply = CommentReply::factory()->for($deleted)->create([
        'id' => 'r-delete',
        'body' => 'Preserve this in undo',
        'created_at' => '2026-07-27 10:00:00.000000',
        'updated_at' => '2026-07-27 10:01:00.000000',
    ]);
    $comments = [
        ['id' => $deleted->id, 'fileId' => 'ctx-agents', 'file' => 'AGENTS.md'],
        ['id' => $kept->id, 'fileId' => 'ctx-keep', 'file' => 'CLAUDE.md'],
    ];

    $result = $this->action->handle(
        '/tmp/thread-deletion',
        $this->project->id,
        $comments,
        [$deleted->id],
        CommentSurface::Context,
    );

    expect($result)->not->toBeNull()
        ->and($result->remainingComments)->toBe([$comments[1]])
        ->and($result->snapshots)->toHaveCount(1)
        ->and($result->snapshots[0]['comment'])->toMatchArray([
            'id' => 'c-delete',
            'fileId' => 'ctx-agents',
            'file' => 'AGENTS.md',
        ])
        ->and($result->snapshots[0]['replies'][0])->toMatchArray([
            'id' => 'r-delete',
            'body' => 'Preserve this in undo',
            'createdAt' => '2026-07-27T10:00:00.000000Z',
            'updatedAt' => '2026-07-27T10:01:00.000000Z',
        ])
        ->and(Comment::query()->find($deleted->id))->toBeNull()
        ->and(CommentReply::query()->find($reply->id))->toBeNull()
        ->and(Comment::query()->find($kept->id))->not->toBeNull();
});

test('deletes only roots from the requested surface and workspace', function () {
    $context = Comment::factory()->for($this->project)->create([
        'id' => 'c-context',
        'repo_path' => '/tmp/thread-deletion',
        'origin_ref' => Comment::ORIGIN_CONTEXT,
    ]);
    $review = Comment::factory()->for($this->project)->create([
        'id' => 'c-review',
        'repo_path' => '/tmp/thread-deletion',
        'origin_ref' => 'working',
    ]);
    $otherProject = Project::factory()->create(['path' => '/tmp/other']);
    $foreign = Comment::factory()->for($otherProject)->create([
        'id' => 'c-foreign',
        'repo_path' => '/tmp/other',
        'origin_ref' => Comment::ORIGIN_CONTEXT,
    ]);
    $comments = collect([$context, $review, $foreign])
        ->map(fn (Comment $comment): array => [
            'id' => $comment->id,
            'fileId' => 'file-'.$comment->id,
            'file' => $comment->file_path,
        ])
        ->all();

    $result = $this->action->handle(
        '/tmp/thread-deletion',
        $this->project->id,
        $comments,
        [$context->id, $review->id, $foreign->id],
        CommentSurface::Context,
    );

    expect($result->snapshots)->toHaveCount(1)
        ->and($result->snapshots[0]['comment']['id'])->toBe('c-context')
        ->and(collect($result->remainingComments)->pluck('id')->all())->toBe(['c-review', 'c-foreign'])
        ->and(Comment::query()->find($context->id))->toBeNull()
        ->and(Comment::query()->find($review->id))->not->toBeNull()
        ->and(Comment::query()->find($foreign->id))->not->toBeNull();
});

test('rolls deletion back when the transaction fails', function () {
    $comment = Comment::factory()->for($this->project)->create([
        'id' => 'c-rollback',
        'repo_path' => '/tmp/thread-deletion',
        'origin_ref' => 'working',
    ]);
    $reply = CommentReply::factory()->for($comment)->create(['id' => 'r-rollback']);
    $shouldFail = true;

    DB::listen(function (QueryExecuted $query) use (&$shouldFail): void {
        if (! $shouldFail || ! Str::startsWith(Str::lower($query->sql), 'delete from')) {
            return;
        }

        $shouldFail = false;

        throw new RuntimeException('Stop after the delete statement.');
    });

    expect(fn () => $this->action->handle(
        '/tmp/thread-deletion',
        $this->project->id,
        [['id' => $comment->id, 'fileId' => 'file-rollback', 'file' => $comment->file_path]],
        [$comment->id],
        CommentSurface::Review,
    ))->toThrow(RuntimeException::class);

    expect(Comment::query()->find($comment->id))->not->toBeNull()
        ->and(CommentReply::query()->find($reply->id))->not->toBeNull();
});

test('returns null when no requested root belongs to the view and scope', function () {
    expect($this->action->handle(
        '/tmp/thread-deletion',
        $this->project->id,
        [],
        ['c-missing'],
        CommentSurface::Review,
    ))->toBeNull();
});

<?php

use App\Actions\CommentReplyWorkflowAction;
use App\DTOs\CommentAuthor;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['path' => '/tmp/replies']);
    $this->comment = Comment::factory()->for($this->project)->create([
        'id' => 'c-root',
        'repo_path' => '/tmp/replies',
        'file_path' => 'app/Foo.php',
        'submitted_at' => now(),
    ]);
    $this->workflow = app(CommentReplyWorkflowAction::class);
});

afterEach(fn () => Carbon::setTestNow());

test('adds replies to submitted comments and returns the canonical thread', function () {
    $mutation = $this->workflow->handle(
        '/tmp/replies',
        $this->project->id,
        'c-root',
        CommentAuthor::human(),
        '  Follow-up  ',
    );

    expect($mutation->commentId)->toBe('c-root')
        ->and($mutation->filePath)->toBe('app/Foo.php')
        ->and($mutation->replies)->toHaveCount(1)
        ->and($mutation->replies[0]['id'])->toStartWith('r-')
        ->and($mutation->replies[0])->toMatchArray([
            'authorType' => 'human',
            'authorKey' => 'rfa-ui',
            'body' => 'Follow-up',
        ]);
});

test('accepts the same unbounded text bodies as root comments', function () {
    $body = str_repeat('conversation ', 19_999).'conversation';

    $mutation = $this->workflow->handle(
        '/tmp/replies',
        $this->project->id,
        'c-root',
        CommentAuthor::human(),
        $body,
    );

    expect($mutation->replies[0]['body'])->toBe($body);
});

test('cannot add to unknown or out-of-scope roots', function (string $commentId) {
    if ($commentId === 'c-other') {
        $otherProject = Project::factory()->create(['path' => '/tmp/other']);
        Comment::factory()->for($otherProject)->create([
            'id' => 'c-other',
            'repo_path' => '/tmp/other',
        ]);
    }

    $this->workflow->handle(
        '/tmp/replies',
        $this->project->id,
        $commentId,
        CommentAuthor::human(),
        'No access',
    );
})->with(['c-missing', 'c-other'])->throws(ModelNotFoundException::class);

test('updates only replies owned by the author identity', function () {
    $reply = CommentReply::factory()->for($this->comment)->agent()->create();

    $this->workflow->update(
        '/tmp/replies',
        $this->project->id,
        $reply->id,
        CommentAuthor::human(),
        'Not mine',
    );
})->throws(ModelNotFoundException::class);

test('updates preserve identity and creation time while exposing the edit timestamp', function () {
    $reply = CommentReply::factory()->for($this->comment)->create([
        'id' => 'r-edit',
        'body' => 'Before',
        'created_at' => '2026-07-27 10:00:00.000000',
        'updated_at' => '2026-07-27 10:00:00.000000',
    ]);
    Carbon::setTestNow('2026-07-27 10:01:00');

    $mutation = $this->workflow->update(
        '/tmp/replies',
        $this->project->id,
        $reply->id,
        CommentAuthor::human(),
        'After',
    );

    expect($mutation->replies[0]['id'])->toBe('r-edit')
        ->and($mutation->replies[0]['body'])->toBe('After')
        ->and($mutation->replies[0]['createdAt'])->toBe('2026-07-27T10:00:00.000000Z')
        ->and($mutation->replies[0]['updatedAt'])->toBe('2026-07-27T10:01:00.000000Z');
});

test('deletes and restores a reply with undo metadata', function () {
    $reply = CommentReply::factory()->for($this->comment)->create([
        'id' => 'r-undo',
        'body' => 'Restore me',
        'created_at' => '2026-07-27 10:00:00',
        'updated_at' => '2026-07-27 10:01:00',
    ]);

    $deleted = $this->workflow->delete(
        '/tmp/replies',
        $this->project->id,
        $reply->id,
        CommentAuthor::human(),
    );

    expect($deleted->replies)->toBe([])
        ->and($deleted->undo)->toMatchArray([
            'type' => 'delete-reply',
            'message' => 'Reply deleted',
        ])
        ->and(CommentReply::query()->find('r-undo'))->toBeNull();

    $restored = $this->workflow->restore(
        '/tmp/replies',
        $this->project->id,
        $deleted->undo['payload'],
    );

    expect($restored->replies)->toHaveCount(1)
        ->and($restored->replies[0])->toMatchArray([
            'id' => 'r-undo',
            'body' => 'Restore me',
        ]);
});

test('cannot mutate replies outside the selected project', function () {
    $otherProject = Project::factory()->create(['path' => '/tmp/other']);
    $reply = CommentReply::factory()
        ->for(Comment::factory()->for($otherProject)->create(['repo_path' => '/tmp/other']))
        ->create();

    $this->workflow->delete(
        '/tmp/replies',
        $this->project->id,
        $reply->id,
        CommentAuthor::human(),
    );
})->throws(ModelNotFoundException::class);

test('rejects blank reply bodies', function () {
    $this->workflow->handle(
        '/tmp/replies',
        $this->project->id,
        'c-root',
        CommentAuthor::human(),
        ' ',
    );
})->throws(ValidationException::class);

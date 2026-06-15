<?php

use App\Actions\ReviewCommentWorkflowAction;
use App\DTOs\DiffTarget;
use App\Models\Comment;
use App\Services\GitFileContentService;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->gitFileContent = Mockery::mock(GitFileContentService::class);
    $this->gitFileContent->shouldReceive('hashForSource')->byDefault()->andReturn('content-hash');
    app()->instance(GitFileContentService::class, $this->gitFileContent);

    $this->action = app(ReviewCommentWorkflowAction::class);
    $this->repoPath = '/tmp/'.$this->faker->word();
    $this->target = DiffTarget::workingDirectory();
    $this->files = [
        ['id' => 'file-abc', 'path' => 'src/hello.php'],
        ['id' => 'file-def', 'path' => 'src/world.php'],
    ];

    $this->add = fn (array $comments, string $fileId = 'file-abc', string $body = 'note', bool $isDraft = false) => $this->action->handle(
        $this->repoPath,
        null,
        $this->target,
        $this->files,
        $comments,
        $fileId,
        'right',
        1,
        1,
        $body,
        $isDraft,
    );
});

// -- add --

test('handle appends the new comment and asks to skip render after a divergence check', function () {
    $mutation = ($this->add)([]);

    expect($mutation)->not->toBeNull()
        ->and($mutation->comments)->toHaveCount(1)
        ->and($mutation->comments[0]['fileId'])->toBe('file-abc')
        ->and($mutation->comments[0]['body'])->toBe('note')
        ->and($mutation->affectedFileIds)->toBe(['file-abc'])
        ->and($mutation->undo)->toBeNull()
        ->and($mutation->checksDivergence)->toBeTrue()
        ->and($mutation->skipsRender)->toBeTrue();

    expect(Comment::where('id', $mutation->comments[0]['id'])->exists())->toBeTrue();
});

test('handle preserves comments already in the pool', function () {
    $existing = [['id' => 'c-existing', 'fileId' => 'file-def', 'body' => 'old']];

    $mutation = ($this->add)($existing);

    expect($mutation->comments)->toHaveCount(2)
        ->and($mutation->comments[0]['id'])->toBe('c-existing');
});

test('handle returns null when the body is empty', function () {
    expect(($this->add)([], body: '   '))->toBeNull();
});

// -- update --

test('update changes the body and draft flag and asks to skip render without a divergence check', function () {
    $added = ($this->add)([], body: 'first')->comments;
    $commentId = $added[0]['id'];

    $mutation = $this->action->update($added, $commentId, 'edited', true);

    expect($mutation)->not->toBeNull()
        ->and($mutation->comments[0]['body'])->toBe('edited')
        ->and($mutation->comments[0]['isDraft'])->toBeTrue()
        ->and($mutation->affectedFileIds)->toBe(['file-abc'])
        ->and($mutation->undo)->toBeNull()
        ->and($mutation->checksDivergence)->toBeFalse()
        ->and($mutation->skipsRender)->toBeTrue();

    expect(Comment::find($commentId)->body)->toBe('edited');
});

test('update returns null when the comment is not in the pool', function () {
    expect($this->action->update([], 'c-missing', 'edited'))->toBeNull();
});

test('update returns null when no row matches the id', function () {
    $pool = [['id' => 'c-orphan', 'fileId' => 'file-abc', 'body' => 'old']];

    expect($this->action->update($pool, 'c-orphan', 'edited'))->toBeNull();
});

// -- delete --

test('delete removes the comment and offers undo while rendering the parent', function () {
    $added = ($this->add)([])->comments;
    $commentId = $added[0]['id'];

    $mutation = $this->action->delete($added, $commentId);

    expect($mutation)->not->toBeNull()
        ->and($mutation->comments)->toBeEmpty()
        ->and($mutation->affectedFileIds)->toBe(['file-abc'])
        ->and($mutation->undo['type'])->toBe('delete')
        ->and($mutation->undo['payload'][0]['id'])->toBe($commentId)
        ->and($mutation->undo['message'])->toBe('Comment deleted')
        ->and($mutation->checksDivergence)->toBeTrue()
        ->and($mutation->skipsRender)->toBeFalse();

    expect(Comment::find($commentId))->toBeNull();
});

test('delete returns null on an invalid id', function () {
    expect($this->action->delete([], 'not-a-comment-id'))->toBeNull();
});

test('delete of a valid id absent from the pool offers no undo and refreshes no file', function () {
    $pool = [['id' => 'c-loaded', 'fileId' => 'file-abc', 'body' => 'kept']];

    $mutation = $this->action->delete($pool, 'c-other');

    expect($mutation)->not->toBeNull()
        ->and($mutation->comments)->toBe($pool)
        ->and($mutation->affectedFileIds)->toBe([])
        ->and($mutation->undo)->toBeNull()
        ->and($mutation->checksDivergence)->toBeTrue()
        ->and($mutation->skipsRender)->toBeFalse();
});

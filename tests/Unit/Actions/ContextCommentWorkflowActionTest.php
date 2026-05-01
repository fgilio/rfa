<?php

use App\Actions\AddCommentAction;
use App\Actions\ContextCommentWorkflowAction;
use App\DTOs\DiffTarget;
use App\Models\Comment;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->repo = $this->createTempDirectory('rfa_ctxworkflow_');
    file_put_contents($this->repo.'/CLAUDE.md', "rule one\nrule two\nrule three\n");

    $this->action = app(ContextCommentWorkflowAction::class);
    $this->files = [
        [
            'id' => 'ctx-'.hash('xxh128', 'CLAUDE.md'),
            'path' => 'CLAUDE.md',
            'absolutePath' => $this->repo.'/CLAUDE.md',
            'lineCount' => 3,
        ],
    ];
});

test('handle returns a placed comment stamped with the context-file origin_ref', function () {
    $body = $this->faker->sentence();
    $fileId = $this->files[0]['id'];

    $result = $this->action->handle($this->repo, null, $this->files, $fileId, 'right', 2, 2, $body);

    expect($result)->not->toBeNull();
    expect($result['id'])->toStartWith('c-');
    expect($result['fileId'])->toBe($fileId);
    expect($result['file'])->toBe('CLAUDE.md');
    expect($result['side'])->toBe('right');
    expect($result['startLine'])->toBe(2);
    expect($result['body'])->toBe($body);
    expect($result['originRef'])->toBe(ContextCommentWorkflowAction::ORIGIN_REF);
    expect($result['fileContentHash'])->toBe(hash_file('xxh128', $this->repo.'/CLAUDE.md'));
    expect($result['anchorStatus'])->toBe('placed');

    $row = Comment::find($result['id']);
    expect($row->origin_ref)->toBe('context-file');
    expect($row->repo_path)->toBe($this->repo);
    expect($row->file_path)->toBe('CLAUDE.md');
});

test('handle accepts file-level comments with null lines', function () {
    $fileId = $this->files[0]['id'];

    $result = $this->action->handle($this->repo, null, $this->files, $fileId, 'file', null, null, 'general note');

    expect($result['side'])->toBe('file');
    expect($result['startLine'])->toBeNull();
    expect($result['endLine'])->toBeNull();
});

test('handle records draft state', function () {
    $fileId = $this->files[0]['id'];

    $result = $this->action->handle($this->repo, null, $this->files, $fileId, 'right', 1, 1, 'draft note', isDraft: true);

    expect($result['isDraft'])->toBeTrue();
    expect(Comment::find($result['id'])->is_draft)->toBeTrue();
});

test('handle rejects empty body', function () {
    $fileId = $this->files[0]['id'];

    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'right', 1, 1, ''))->toBeNull();
    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'right', 1, 1, '   '))->toBeNull();
});

test('handle rejects unknown file id', function () {
    $result = $this->action->handle($this->repo, null, $this->files, 'ctx-nope', 'right', 1, 1, 'body');

    expect($result)->toBeNull();
});

test('handle rejects left-side comments (one-sided diff)', function () {
    $fileId = $this->files[0]['id'];

    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'left', 1, 1, 'body'))->toBeNull();
});

test('handle rejects file-level comments with line numbers', function () {
    $fileId = $this->files[0]['id'];

    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'file', 1, 1, 'body'))->toBeNull();
});

test('handle rejects line-level comments with no startLine', function () {
    $fileId = $this->files[0]['id'];

    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'right', null, 5, 'body'))->toBeNull();
});

test('handle rejects anchors past the file end (stale payload guard)', function () {
    $fileId = $this->files[0]['id'];

    // File is 3 lines; anything past line 3 is an obviously-stale anchor.
    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'right', 4, 4, 'past EOF'))->toBeNull();
    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'right', 2, 4, 'range past EOF'))->toBeNull();
    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'right', 0, 1, 'sub-1 start'))->toBeNull();

    // In-bounds is still accepted.
    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'right', 3, 3, 'last line'))->not->toBeNull();
});

test('handle skips bounds-check when lineCount is unknown', function () {
    $fileId = 'ctx-noline';
    $files = [
        [
            'id' => $fileId,
            'path' => 'CLAUDE.md',
            'absolutePath' => $this->repo.'/CLAUDE.md',
            // lineCount missing on purpose (e.g. scanner couldn't stat the file).
        ],
    ];

    expect($this->action->handle($this->repo, null, $files, $fileId, 'right', 9999, 9999, 'no upper bound'))->not->toBeNull();
});

test('handle accepts file-level comments regardless of lineCount', function () {
    $fileId = $this->files[0]['id'];

    // File is 3 lines; file-level has null lines so bounds-check is moot.
    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'file', null, null, 'file note'))->not->toBeNull();
});

test('handle rejects ranges where startLine > endLine', function () {
    $fileId = $this->files[0]['id'];

    expect($this->action->handle($this->repo, null, $this->files, $fileId, 'right', 5, 1, 'body'))->toBeNull();
});

test('update mutates body and draft flag, scoped to context-file rows', function () {
    $fileId = $this->files[0]['id'];
    $created = $this->action->handle($this->repo, null, $this->files, $fileId, 'right', 1, 1, 'orig');

    $ok = $this->action->update($created['id'], 'edited', isDraft: true);

    expect($ok)->toBeTrue();
    $row = Comment::find($created['id']);
    expect($row->body)->toBe('edited');
    expect($row->is_draft)->toBeTrue();
});

test('update rejects blank bodies, mirroring the create-time rule', function () {
    $fileId = $this->files[0]['id'];
    $created = $this->action->handle($this->repo, null, $this->files, $fileId, 'right', 1, 1, 'orig');

    expect($this->action->update($created['id'], ''))->toBeFalse();
    expect($this->action->update($created['id'], '   '))->toBeFalse();

    expect(Comment::find($created['id'])->body)->toBe('orig');
});

test('update refuses to touch comments owned by other origin_refs', function () {
    $reviewComment = app(AddCommentAction::class)->handle(
        '/tmp/whatever',
        null,
        DiffTarget::workingDirectory(),
        [['id' => 'file-x', 'path' => 'src/a.php']],
        'file-x',
        'right',
        1,
        1,
        'review body',
    );

    $ok = $this->action->update($reviewComment['id'], 'hijacked', false);

    expect($ok)->toBeFalse();
    expect(Comment::find($reviewComment['id'])->body)->toBe('review body');
});

test('delete removes the row and prunes the view-state list', function () {
    $fileId = $this->files[0]['id'];
    $a = $this->action->handle($this->repo, null, $this->files, $fileId, 'right', 1, 1, 'a');
    $b = $this->action->handle($this->repo, null, $this->files, $fileId, 'right', 2, 2, 'b');

    $remaining = $this->action->delete([$a, $b], $a['id']);

    expect($remaining)->toHaveCount(1);
    expect($remaining[0]['id'])->toBe($b['id']);
    expect(Comment::find($a['id']))->toBeNull();
    expect(Comment::find($b['id']))->not->toBeNull();
});

test('delete refuses ids without the c- prefix', function () {
    $result = $this->action->delete([], 'bogus-id');

    expect($result)->toBeNull();
});

test('context-file comments coexist with review comments on the same file_path', function () {
    $fileId = $this->files[0]['id'];

    // Same (repo_path, file_path), different origin_refs.
    $contextRow = $this->action->handle($this->repo, null, $this->files, $fileId, 'right', 1, 1, 'context comment');

    $reviewRow = app(AddCommentAction::class)->handle(
        $this->repo,
        null,
        DiffTarget::workingDirectory(),
        [['id' => 'file-claude', 'path' => 'CLAUDE.md']],
        'file-claude',
        'right',
        1,
        1,
        'review comment',
    );

    expect($contextRow['id'])->not->toBe($reviewRow['id']);

    $rows = Comment::where('repo_path', $this->repo)
        ->where('file_path', 'CLAUDE.md')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows->pluck('origin_ref')->sort()->values()->all())
        ->toBe(['context-file', 'working']);
});

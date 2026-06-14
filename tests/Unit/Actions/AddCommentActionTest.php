<?php

use App\Actions\AddCommentAction;
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

    $this->action = app(AddCommentAction::class);
    $this->files = [
        ['id' => 'file-abc', 'path' => 'src/hello.php'],
    ];
    $this->repoPath = '/tmp/'.$this->faker->word();
    $this->target = DiffTarget::workingDirectory();
});

test('returns comment array on valid input', function () {
    $body = $this->faker->sentence();

    $result = $this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'right', 10, 10, $body);

    expect($result)->not->toBeNull();
    expect($result['id'])->toStartWith('c-');
    expect($result['fileId'])->toBe('file-abc');
    expect($result['file'])->toBe('src/hello.php');
    expect($result['side'])->toBe('right');
    expect($result['startLine'])->toBe(10);
    expect($result['endLine'])->toBe(10);
    expect($result['body'])->toBe($body);
    expect($result['originRef'])->toBe('working');
    expect($result['fileContentHash'])->toBe('content-hash');
    expect($result['anchorStatus'])->toBe('placed');
});

test('persists the comment row to the comments table', function () {
    $result = $this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'right', 1, 1, 'body');

    $row = Comment::find($result['id']);

    expect($row)->not->toBeNull();
    expect($row->repo_path)->toBe($this->repoPath);
    expect($row->project_id)->toBeNull();
    expect($row->origin_ref)->toBe('working');
    expect($row->file_path)->toBe('src/hello.php');
    expect($row->side)->toBe('right');
    expect($row->start_line)->toBe(1);
    expect($row->end_line)->toBe(1);
    expect($row->file_content_hash)->toBe('content-hash');
    expect($row->is_draft)->toBeFalse();
    expect($row->submitted_at)->toBeNull();
});

test('captures origin_ref from target head for immutable commits', function () {
    $target = DiffTarget::commit('abc123');

    $result = $this->action->handle($this->repoPath, null, $target, $this->files, 'file-abc', 'right', 1, 1, 'body');

    expect($result['originRef'])->toBe('abc123');
});

test('persists the line snippet when provided', function () {
    $snippet = "echo 'hello';\necho 'world';";

    $result = $this->action->handle(
        $this->repoPath,
        null,
        $this->target,
        $this->files,
        'file-abc',
        'right',
        1,
        2,
        'body',
        false,
        $snippet,
    );

    expect($result['lineSnippet'])->toBe($snippet);
    expect(Comment::find($result['id'])->line_snippet)->toBe($snippet);
});

test('returns null for empty body', function () {
    expect($this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'right', 1, 1, ''))->toBeNull();
    expect($this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'right', 1, 1, '   '))->toBeNull();
});

test('returns null for invalid file id', function () {
    $result = $this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-nonexistent', 'right', 1, 1, 'body');

    expect($result)->toBeNull();
});

test('returns null for invalid side', function () {
    $result = $this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'invalid', 1, 1, 'body');

    expect($result)->toBeNull();
});

test('accepts file-level comments with null lines', function () {
    $result = $this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'file', null, null, 'general note');

    expect($result)->not->toBeNull();
    expect($result['startLine'])->toBeNull();
    expect($result['endLine'])->toBeNull();
});

test('returns null for file-level comment with line numbers', function () {
    expect($this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'file', 1, 5, 'body'))->toBeNull();
    expect($this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'file', 1, null, 'body'))->toBeNull();
    expect($this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'file', null, 5, 'body'))->toBeNull();
});

test('returns null for line comment with null startLine', function () {
    expect($this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'right', null, 5, 'body'))->toBeNull();
    expect($this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'left', null, null, 'body'))->toBeNull();
});

test('returns null when startLine exceeds endLine', function () {
    expect($this->action->handle($this->repoPath, null, $this->target, $this->files, 'file-abc', 'right', 10, 5, 'body'))->toBeNull();
});

test('hashes left-side comments on renamed files using oldPath', function () {
    $gitFileContent = Mockery::mock(GitFileContentService::class);
    $gitFileContent->shouldReceive('hashForSource')
        ->with('/tmp/repo', gitSourceSpec('parent-sha', 'src/old.php'))
        ->andReturn('left-hash');
    app()->instance(GitFileContentService::class, $gitFileContent);

    $action = app(AddCommentAction::class);
    $files = [['id' => 'file-renamed', 'path' => 'src/new.php', 'oldPath' => 'src/old.php']];

    $result = $action->handle('/tmp/repo', null, DiffTarget::range('parent-sha', 'abc123'), $files, 'file-renamed', 'left', 3, 3, 'body');

    expect($result['fileContentHash'])->toBe('left-hash');
});

test('hashes right-side comments on renamed files using the post-rename path', function () {
    $gitFileContent = Mockery::mock(GitFileContentService::class);
    $gitFileContent->shouldReceive('hashForSource')
        ->with('/tmp/repo', gitSourceSpec('abc123', 'src/new.php'))
        ->andReturn('right-hash');
    app()->instance(GitFileContentService::class, $gitFileContent);

    $action = app(AddCommentAction::class);
    $files = [['id' => 'file-renamed', 'path' => 'src/new.php', 'oldPath' => 'src/old.php']];

    $result = $action->handle('/tmp/repo', null, DiffTarget::range('parent-sha', 'abc123'), $files, 'file-renamed', 'right', 3, 3, 'body');

    expect($result['fileContentHash'])->toBe('right-hash');
});

test('hashes external-file comments off disk and stamps origin_ref=external', function () {
    $tmp = $this->createTempDirectory('rfa_addcomment_ext_');
    $absolute = $tmp.'/note.md';
    file_put_contents($absolute, "external content\n");

    // Drop the beforeEach mock so we exercise the real hashAtAbsolute path.
    app()->forgetInstance(GitFileContentService::class);
    $action = app(AddCommentAction::class);
    $files = [[
        'id' => 'file-ext',
        'path' => 'external/notes/note.md',
        'isExternal' => true,
        'externalAbsolutePath' => $absolute,
    ]];

    $result = $action->handle('/tmp/repo', null, DiffTarget::workingDirectory(), $files, 'file-ext', 'right', 1, 1, 'body');

    expect($result['originRef'])->toBe('external');
    expect($result['fileContentHash'])->toBe(hash_file('xxh128', $absolute));

    $row = Comment::find($result['id']);
    expect($row->origin_ref)->toBe('external');
    expect($row->file_path)->toBe('external/notes/note.md');
});

test('file-level comments on renamed files hash the post-rename path at `to`', function () {
    $gitFileContent = Mockery::mock(GitFileContentService::class);
    $gitFileContent->shouldReceive('hashForSource')
        ->with('/tmp/repo', gitSourceSpec('abc123', 'src/new.php'))
        ->andReturn('file-hash');
    app()->instance(GitFileContentService::class, $gitFileContent);

    $action = app(AddCommentAction::class);
    $files = [['id' => 'file-renamed', 'path' => 'src/new.php', 'oldPath' => 'src/old.php']];

    $result = $action->handle('/tmp/repo', null, DiffTarget::range('parent-sha', 'abc123'), $files, 'file-renamed', 'file', null, null, 'body');

    expect($result['fileContentHash'])->toBe('file-hash');
});

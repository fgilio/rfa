<?php

use App\Actions\ContextCommentWorkflowAction;
use App\Actions\LoadContextCommentsAction;
use App\Models\Comment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->repo = $this->createTempDirectory('rfa_loadctx_');
    $this->action = app(LoadContextCommentsAction::class);
});

function writeContextFile(string $repo, string $path, string $content): string
{
    $absolute = $repo.'/'.$path;
    @mkdir(dirname($absolute), 0777, true);
    file_put_contents($absolute, $content);

    return $absolute;
}

function makeContextComment(array $overrides = []): Comment
{
    return Comment::create(array_merge([
        'id' => 'c-'.bin2hex(random_bytes(8)),
        'project_id' => null,
        'repo_path' => $overrides['repo_path'] ?? '/tmp/repo',
        'origin_ref' => ContextCommentWorkflowAction::ORIGIN_REF,
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'start_line' => 2,
        'end_line' => 2,
        'file_content_hash' => null,
        'line_snippet' => null,
        'body' => 'note',
        'is_draft' => false,
    ], $overrides));
}

test('handle returns rows in created_at order, scoped to context-file origin and unsubmitted', function () {
    $absolute = writeContextFile($this->repo, 'CLAUDE.md', "rule one\nrule two\nrule three\n");
    $hash = hash_file('xxh128', $absolute);

    $first = makeContextComment([
        'repo_path' => $this->repo,
        'file_content_hash' => $hash,
        'body' => 'first',
    ]);
    $first->created_at = now()->subMinutes(2);
    $first->save();

    $second = makeContextComment([
        'repo_path' => $this->repo,
        'file_content_hash' => $hash,
        'body' => 'second',
    ]);
    $second->created_at = now()->subMinute();
    $second->save();

    // A submitted row of ours and a foreign-origin row both must be excluded.
    makeContextComment(['repo_path' => $this->repo, 'submitted_at' => now()]);
    Comment::create([
        'id' => 'c-other',
        'project_id' => null,
        'repo_path' => $this->repo,
        'origin_ref' => 'working',
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'start_line' => 1,
        'end_line' => 1,
        'body' => 'review-side',
        'is_draft' => false,
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows)->toHaveCount(2);
    expect(array_column($rows, 'body'))->toBe(['first', 'second']);
});

test('placed status when the stored hash still matches the file', function () {
    $absolute = writeContextFile($this->repo, 'CLAUDE.md', "rule one\nrule two\nrule three\n");
    $hash = hash_file('xxh128', $absolute);

    makeContextComment([
        'repo_path' => $this->repo,
        'file_content_hash' => $hash,
        'line_snippet' => 'rule two',
        'start_line' => 2,
        'end_line' => 2,
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('placed');
    expect($rows[0]['startLine'])->toBe(2);
    expect($rows[0]['endLine'])->toBe(2);
});

test('drifted hash with recoverable snippet shifts startLine and endLine', function () {
    writeContextFile($this->repo, 'CLAUDE.md', "preamble line\nnew context above\nrule one\nrule two\nrule three\n");

    makeContextComment([
        'repo_path' => $this->repo,
        // Hash from before the preamble was added.
        'file_content_hash' => 'stale-hash',
        'line_snippet' => "rule two\nrule three",
        'start_line' => 2,
        'end_line' => 3,
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('placed');
    // "rule two" / "rule three" now sit on lines 4 and 5.
    expect($rows[0]['startLine'])->toBe(4);
    expect($rows[0]['endLine'])->toBe(5);
});

test('drifted hash with no snippet match flips anchor status to unplaced', function () {
    writeContextFile($this->repo, 'CLAUDE.md', "totally rewritten file\nnothing matches anymore\n");

    makeContextComment([
        'repo_path' => $this->repo,
        'file_content_hash' => 'stale-hash',
        'line_snippet' => 'rule that no longer exists',
        'start_line' => 2,
        'end_line' => 2,
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('unplaced');
    expect($rows[0]['startLine'])->toBe(2);
    expect($rows[0]['endLine'])->toBe(2);
});

test('missing file flips every comment on it to unplaced', function () {
    // Note: file is not written.
    makeContextComment([
        'repo_path' => $this->repo,
        'file_content_hash' => 'stale-hash',
        'line_snippet' => 'rule one',
        'start_line' => 1,
        'end_line' => 1,
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('unplaced');
});

test('file-level comments survive hash drift and stay placed', function () {
    writeContextFile($this->repo, 'CLAUDE.md', "rewritten content\n");

    makeContextComment([
        'repo_path' => $this->repo,
        'side' => 'file',
        'start_line' => null,
        'end_line' => null,
        'file_content_hash' => 'stale-hash',
        'line_snippet' => null,
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('placed');
    expect($rows[0]['side'])->toBe('file');
});

test('snippet appearing twice picks the occurrence closest to the original start_line', function () {
    writeContextFile($this->repo, 'CLAUDE.md', implode("\n", [
        'banner one',          // 1
        'duplicated rule',     // 2 — original anchor
        'middle filler',       // 3
        'middle filler',       // 4
        'duplicated rule',     // 5 — now also matches
        '',
    ]));

    makeContextComment([
        'repo_path' => $this->repo,
        'file_content_hash' => 'stale-hash',
        'line_snippet' => 'duplicated rule',
        'start_line' => 2,
        'end_line' => 2,
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('placed');
    expect($rows[0]['startLine'])->toBe(2);
});

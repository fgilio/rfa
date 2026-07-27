<?php

use App\Actions\LoadContextCommentsAction;
use App\Models\Comment;
use App\Models\CommentReply;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->repo = $this->createTempDirectory('rfa_loadctx_');
    $this->action = app(LoadContextCommentsAction::class);

    $this->writeContextFile = function (string $path, string $content): string {
        $absolute = $this->repo.'/'.$path;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $content);

        return $absolute;
    };
});

test('handle returns rows in created_at order, scoped to context-file origin and unsubmitted', function () {
    $absolute = ($this->writeContextFile)('CLAUDE.md', "rule one\nrule two\nrule three\n");
    $hash = hash_file('xxh128', $absolute);

    $first = Comment::factory()->context()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
        'file_content_hash' => $hash,
        'body' => 'first',
        'created_at' => now()->subMinutes(2),
    ]);

    Comment::factory()->context()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
        'file_content_hash' => $hash,
        'body' => 'second',
        'created_at' => now()->subMinute(),
    ]);

    // A submitted row of ours and a foreign-origin row both must be excluded.
    Comment::factory()->context()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
        'submitted_at' => now(),
    ]);
    Comment::factory()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
        'origin_ref' => 'working',
        'file_path' => 'CLAUDE.md',
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows)->toHaveCount(2);
    expect(array_column($rows, 'body'))->toBe(['first', 'second']);
});

test('eager loads ordered replies into the normalized thread shape', function () {
    $absolute = ($this->writeContextFile)('CLAUDE.md', "rule one\nrule two\n");
    $comment = Comment::factory()->context()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
        'file_content_hash' => hash_file('xxh128', $absolute),
    ]);
    CommentReply::factory()->for($comment)->create([
        'id' => 'r-second',
        'body' => 'Second',
        'created_at' => '2026-07-27 10:01:00',
    ]);
    CommentReply::factory()->for($comment)->agent()->create([
        'id' => 'r-first',
        'body' => 'First',
        'created_at' => '2026-07-27 10:00:00',
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect(array_column($rows[0]['replies'], 'id'))->toBe(['r-first', 'r-second'])
        ->and($rows[0]['replies'][0])->toMatchArray([
            'authorType' => 'agent',
            'authorKey' => 'codex-cli',
            'body' => 'First',
        ]);
});

test('placed status when the stored hash still matches the file', function () {
    $absolute = ($this->writeContextFile)('CLAUDE.md', "rule one\nrule two\nrule three\n");

    Comment::factory()->context()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
        'file_content_hash' => hash_file('xxh128', $absolute),
        'line_snippet' => 'rule two',
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('placed');
    expect($rows[0]['startLine'])->toBe(2);
    expect($rows[0]['endLine'])->toBe(2);
});

test('drifted hash with recoverable snippet shifts startLine and endLine', function () {
    ($this->writeContextFile)('CLAUDE.md', "preamble line\nnew context above\nrule one\nrule two\nrule three\n");

    Comment::factory()->context()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
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
    ($this->writeContextFile)('CLAUDE.md', "totally rewritten file\nnothing matches anymore\n");

    Comment::factory()->context()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
        'file_content_hash' => 'stale-hash',
        'line_snippet' => 'rule that no longer exists',
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('unplaced');
    expect($rows[0]['startLine'])->toBe(2);
    expect($rows[0]['endLine'])->toBe(2);
});

test('missing file flips every comment on it to unplaced', function () {
    // Note: file is not written.
    Comment::factory()->context()->create([
        'project_id' => null,
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
    ($this->writeContextFile)('CLAUDE.md', "rewritten content\n");

    Comment::factory()->context()->create([
        'project_id' => null,
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
    ($this->writeContextFile)('CLAUDE.md', implode("\n", [
        'banner one',          // 1
        'duplicated rule',     // 2 — original anchor
        'middle filler',       // 3
        'middle filler',       // 4
        'duplicated rule',     // 5 — now also matches
        '',
    ]));

    Comment::factory()->context()->create([
        'project_id' => null,
        'repo_path' => $this->repo,
        'file_content_hash' => 'stale-hash',
        'line_snippet' => 'duplicated rule',
    ]);

    $rows = $this->action->handle($this->repo, null);

    expect($rows[0]['anchorStatus'])->toBe('placed');
    expect($rows[0]['startLine'])->toBe(2);
});

<?php

use App\Actions\ResolveContextCommentAnchorAction;
use App\Enums\AnchorStatus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->repo = $this->createTempDirectory('rfa_resolve_ctx_');
    $this->action = app(ResolveContextCommentAnchorAction::class);
});

function rawCommentRow(array $overrides = []): array
{
    return array_merge([
        'id' => 'c-test',
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'start_line' => 2,
        'end_line' => 2,
        'body' => 'note',
        'origin_ref' => 'context-file',
        'file_content_hash' => null,
        'line_snippet' => null,
        'is_draft' => false,
        'submitted_at' => null,
    ], $overrides);
}

test('matching hash places the anchor with original lines', function () {
    $absolute = $this->repo.'/CLAUDE.md';
    File::put($absolute, "rule one\nrule two\nrule three\n");

    $rows = $this->action->handle($this->repo, [
        rawCommentRow(['file_content_hash' => hash_file('xxh128', $absolute)]),
    ]);

    expect($rows[0]['anchorStatus'])->toBe(AnchorStatus::Placed->value);
    expect($rows[0]['startLine'])->toBe(2);
    expect($rows[0]['endLine'])->toBe(2);
});

test('drifted hash with recoverable snippet shifts the anchor', function () {
    File::put($this->repo.'/CLAUDE.md', "preamble\nadded above\nrule one\nrule two\n");

    $rows = $this->action->handle($this->repo, [
        rawCommentRow([
            'file_content_hash' => 'stale',
            'line_snippet' => 'rule two',
            'start_line' => 2,
            'end_line' => 2,
        ]),
    ]);

    expect($rows[0]['anchorStatus'])->toBe(AnchorStatus::Placed->value);
    expect($rows[0]['startLine'])->toBe(4);
});

test('recovered end line spans the snippet, not the original wider line range', function () {
    // Comment was stored with start_line=2, end_line=4 (a 3-line span) but its
    // captured snippet is only 2 lines (a row was skipped at capture). After drift
    // the recovered end must be start + 1 (snippet length), never start + 2.
    File::put($this->repo.'/CLAUDE.md', "x\ny\nfirst\nsecond\nz\n");

    $rows = $this->action->handle($this->repo, [
        rawCommentRow([
            'file_content_hash' => 'stale',
            'line_snippet' => "first\nsecond",
            'start_line' => 2,
            'end_line' => 4,
        ]),
    ]);

    expect($rows[0]['anchorStatus'])->toBe(AnchorStatus::Placed->value);
    expect($rows[0]['startLine'])->toBe(3);
    expect($rows[0]['endLine'])->toBe(4);
});

test('drifted hash without a snippet match flips the anchor to Unplaced', function () {
    File::put($this->repo.'/CLAUDE.md', "totally rewritten\n");

    $rows = $this->action->handle($this->repo, [
        rawCommentRow([
            'file_content_hash' => 'stale',
            'line_snippet' => 'rule that no longer exists',
        ]),
    ]);

    expect($rows[0]['anchorStatus'])->toBe(AnchorStatus::Unplaced->value);
});

test('missing file flips every comment on it to Unplaced', function () {
    $rows = $this->action->handle($this->repo, [
        rawCommentRow(['file_content_hash' => 'stale', 'line_snippet' => 'whatever']),
    ]);

    expect($rows[0]['anchorStatus'])->toBe(AnchorStatus::Unplaced->value);
});

test('file-level comments stay Placed when the file exists, hash drift or not', function () {
    File::put($this->repo.'/CLAUDE.md', "rewritten\n");

    $rows = $this->action->handle($this->repo, [
        rawCommentRow([
            'side' => 'file',
            'start_line' => null,
            'end_line' => null,
            'file_content_hash' => 'stale',
        ]),
    ]);

    expect($rows[0]['anchorStatus'])->toBe(AnchorStatus::Placed->value);
});

test('multiple comments on the same file share one filesystem read via the cache', function () {
    File::put($this->repo.'/CLAUDE.md', "rule one\nrule two\n");
    $hash = hash_file('xxh128', $this->repo.'/CLAUDE.md');

    $rows = $this->action->handle($this->repo, [
        rawCommentRow(['id' => 'c-a', 'file_content_hash' => $hash]),
        rawCommentRow(['id' => 'c-b', 'file_content_hash' => $hash, 'start_line' => 1, 'end_line' => 1]),
    ]);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['anchorStatus'])->toBe(AnchorStatus::Placed->value);
    expect($rows[1]['anchorStatus'])->toBe(AnchorStatus::Placed->value);
});

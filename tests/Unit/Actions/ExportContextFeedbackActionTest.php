<?php

use App\Actions\ContextCommentWorkflowAction;
use App\Actions\ExportContextFeedbackAction;
use App\Models\Comment;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->repoA = $this->createTempDirectory('rfa_exportctx_a_');
    $this->repoB = $this->createTempDirectory('rfa_exportctx_b_');
    file_put_contents($this->repoA.'/CLAUDE.md', "rule\n");
    file_put_contents($this->repoB.'/CLAUDE.md', "rule\n");

    $this->workflow = app(ContextCommentWorkflowAction::class);
    $this->action = app(ExportContextFeedbackAction::class);

    $this->filesA = [['id' => 'ctx-a', 'path' => 'CLAUDE.md', 'absolutePath' => $this->repoA.'/CLAUDE.md']];
    $this->filesB = [['id' => 'ctx-b', 'path' => 'CLAUDE.md', 'absolutePath' => $this->repoB.'/CLAUDE.md']];
});

test('only stamps submitted_at on comments that belong to the active repo', function () {
    $own = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 1, 1, 'mine');
    $other = $this->workflow->handle($this->repoB, null, $this->filesB, 'ctx-b', 'right', 1, 1, 'theirs');

    // Caller passes both ids (e.g. tampered/stale Livewire state).
    $this->action->handle($this->repoA, null, [$own, $other], '');

    expect(Comment::find($own['id'])->submitted_at)->not->toBeNull();
    expect(Comment::find($other['id'])->submitted_at)->toBeNull();
});

test('refuses to stamp non-context-file comments even when their id is supplied', function () {
    $context = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 1, 1, 'ctx');

    $review = Comment::create([
        'id' => 'c-review-001',
        'project_id' => null,
        'repo_path' => $this->repoA,
        'origin_ref' => 'working',
        'file_path' => 'CLAUDE.md',
        'side' => 'right',
        'start_line' => 1,
        'end_line' => 1,
        'body' => 'review body',
        'is_draft' => false,
    ]);

    $hijacked = [
        'id' => $review->id,
        'fileId' => 'ctx-a',
        'file' => 'CLAUDE.md',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'review body',
    ];

    $this->action->handle($this->repoA, null, [$context, $hijacked], '');

    expect(Comment::find($context['id'])->submitted_at)->not->toBeNull();
    expect(Comment::find($review->id)->submitted_at)->toBeNull();
});

test('skips draft comments from export and stamping', function () {
    $finalized = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 1, 1, 'finalized');
    $draft = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 2, 2, 'draft body', isDraft: true);

    $result = $this->action->handle($this->repoA, null, [$finalized, $draft], '');

    expect(Comment::find($finalized['id'])->submitted_at)->not->toBeNull();
    expect(Comment::find($draft['id'])->submitted_at)->toBeNull();
    expect($result['submittedIds'])->toBe([$finalized['id']]);
    expect($result['md'])->not->toContain('draft body');
});

test('excludes unplaced comments from export and stamping and reports them', function () {
    $placed = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 1, 1, 'placed body');
    $unplaced = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 2, 2, 'stale body');

    // The anchor resolver marked the second one unplaced (its file drifted past
    // recovery). It must not be exported with a stale line nor stamped submitted.
    $unplaced['anchorStatus'] = 'unplaced';

    $result = $this->action->handle($this->repoA, null, [$placed, $unplaced], '');

    expect(Comment::find($placed['id'])->submitted_at)->not->toBeNull();
    expect(Comment::find($unplaced['id'])->submitted_at)->toBeNull();
    expect($result['md'])->not->toContain('stale body');
    expect($result['submittedIds'])->toBe([$placed['id']]);
    expect($result['excludedComments'])->toHaveCount(1);
    expect($result['excludedComments'][0]['id'])->toBe($unplaced['id']);
});

test('scopes by project_id when given one', function () {
    $project = Project::factory()->create(['path' => $this->repoA]);

    $own = $this->workflow->handle($this->repoA, $project->id, $this->filesA, 'ctx-a', 'right', 1, 1, 'own');
    $other = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 2, 2, 'other');

    $this->action->handle($this->repoA, $project->id, [$own, $other], '');

    expect(Comment::find($own['id'])->submitted_at)->not->toBeNull();
    expect(Comment::find($other['id'])->submitted_at)->toBeNull();
});

<?php

use App\Actions\ClearContextCommentsAction;
use App\Actions\ContextCommentWorkflowAction;
use App\Models\Comment;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->repoA = $this->createTempDirectory('rfa_clearctx_a_');
    $this->repoB = $this->createTempDirectory('rfa_clearctx_b_');
    file_put_contents($this->repoA.'/CLAUDE.md', "rule\n");
    file_put_contents($this->repoB.'/CLAUDE.md', "rule\n");

    $this->workflow = app(ContextCommentWorkflowAction::class);
    $this->action = app(ClearContextCommentsAction::class);

    $this->filesA = [['id' => 'ctx-a', 'path' => 'CLAUDE.md', 'absolutePath' => $this->repoA.'/CLAUDE.md']];
    $this->filesB = [['id' => 'ctx-b', 'path' => 'CLAUDE.md', 'absolutePath' => $this->repoB.'/CLAUDE.md']];
});

test('returns 0 for an empty id list without touching the table', function () {
    $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 1, 1, 'a');

    expect($this->action->handle($this->repoA, null, []))->toBe(0);
    expect(Comment::count())->toBe(1);
});

test('refuses to delete ids that belong to a different repo', function () {
    $a = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 1, 1, 'a');
    $b = $this->workflow->handle($this->repoB, null, $this->filesB, 'ctx-b', 'right', 1, 1, 'b');

    // Caller is "in repo A" but hands us repo B's id (e.g. stale Livewire state).
    $deleted = $this->action->handle($this->repoA, null, [$a['id'], $b['id']]);

    expect($deleted)->toBe(1);
    expect(Comment::find($a['id']))->toBeNull();
    expect(Comment::find($b['id']))->not->toBeNull();
});

test('refuses to delete review comments even when their id is supplied', function () {
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

    $deleted = $this->action->handle($this->repoA, null, [$context['id'], $review->id]);

    expect($deleted)->toBe(1);
    expect(Comment::find($context['id']))->toBeNull();
    expect(Comment::find($review->id))->not->toBeNull();
});

test('scopes by project_id when given one', function () {
    $project = Project::factory()->create(['path' => $this->repoA]);

    $own = $this->workflow->handle($this->repoA, $project->id, $this->filesA, 'ctx-a', 'right', 1, 1, 'own');
    $other = $this->workflow->handle($this->repoA, null, $this->filesA, 'ctx-a', 'right', 2, 2, 'other');

    $deleted = $this->action->handle($this->repoA, $project->id, [$own['id'], $other['id']]);

    expect($deleted)->toBe(1);
    expect(Comment::find($own['id']))->toBeNull();
    expect(Comment::find($other['id']))->not->toBeNull();
});

<?php

use App\Actions\PersistProjectViewAction;
use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'persist-test', 'path' => '/tmp/persist-test']);
    $this->action = app(PersistProjectViewAction::class);
});

test('creates a review_session row on first save', function () {
    $this->action->handle($this->project->id, $this->project->path, LastViewMode::Review, LastViewKind::WorkingTree);

    $row = ReviewSession::where('project_id', $this->project->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->last_view_mode)->toBe(LastViewMode::Review)
        ->and($row->last_view_kind)->toBe(LastViewKind::WorkingTree)
        ->and($row->last_view_from)->toBeNull()
        ->and($row->last_view_to)->toBeNull();
});

test('persists range refs on a Range save', function () {
    $this->action->handle(
        $this->project->id,
        $this->project->path,
        LastViewMode::Review,
        LastViewKind::Range,
        from: 'aaaa1111',
        to: 'bbbb2222',
    );

    $row = ReviewSession::where('project_id', $this->project->id)->first();

    expect($row->last_view_kind)->toBe(LastViewKind::Range)
        ->and($row->last_view_from)->toBe('aaaa1111')
        ->and($row->last_view_to)->toBe('bbbb2222');
});

test('persists since_base as semantic intent and discards the at-save SHA', function () {
    $this->action->handle(
        $this->project->id,
        $this->project->path,
        LastViewMode::Review,
        LastViewKind::SinceBase,
        from: 'merge-base-sha',
    );

    $row = ReviewSession::where('project_id', $this->project->id)->first();

    expect($row->last_view_kind)->toBe(LastViewKind::SinceBase)
        ->and($row->last_view_from)->toBeNull()
        ->and($row->last_view_to)->toBeNull();
});

test('clears review-only columns when saving Context mode', function () {
    $this->action->handle(
        $this->project->id,
        $this->project->path,
        LastViewMode::Review,
        LastViewKind::Range,
        from: 'aaaa',
        to: 'bbbb',
    );

    $this->action->handle(
        $this->project->id,
        $this->project->path,
        LastViewMode::Context,
    );

    $row = ReviewSession::where('project_id', $this->project->id)->first();

    expect($row->last_view_mode)->toBe(LastViewMode::Context)
        ->and($row->last_view_kind)->toBeNull()
        ->and($row->last_view_from)->toBeNull()
        ->and($row->last_view_to)->toBeNull();
});

test('overwrites the same row across multiple saves', function () {
    $this->action->handle($this->project->id, $this->project->path, LastViewMode::Review, LastViewKind::WorkingTree);
    $this->action->handle($this->project->id, $this->project->path, LastViewMode::Review, LastViewKind::Commit, to: 'cafe1234');
    $this->action->handle($this->project->id, $this->project->path, LastViewMode::Context);

    expect(ReviewSession::where('project_id', $this->project->id)->count())->toBe(1);
});

test('preserves existing global_comment on save', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'global_comment' => 'do not lose me',
    ]);

    $this->action->handle($this->project->id, $this->project->path, LastViewMode::Review, LastViewKind::WorkingTree);

    $row = ReviewSession::where('project_id', $this->project->id)->first();

    expect($row->global_comment)->toBe('do not lose me')
        ->and($row->last_view_mode)->toBe(LastViewMode::Review);
});

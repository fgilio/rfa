<?php

use App\Actions\CountPersistedCommentsAction;
use App\Models\Comment;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->action = app(CountPersistedCommentsAction::class);
});

test('handle counts the comments scoped to a project', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();

    Comment::factory()->count(2)->create(['project_id' => $project->id]);
    Comment::factory()->create(['project_id' => $other->id]);

    expect($this->action->handle($project->path, $project->id))->toBe(2);
});

test('handle counts repo-scoped comments when there is no project', function () {
    Comment::factory()->count(3)->create(['project_id' => null, 'repo_path' => '/tmp/repo-a']);
    Comment::factory()->create(['project_id' => null, 'repo_path' => '/tmp/repo-b']);

    expect($this->action->handle('/tmp/repo-a', null))->toBe(3);
});

test('handle returns zero when the target has no comments', function () {
    expect($this->action->handle('/tmp/empty', null))->toBe(0);
});

test('exists short-circuits to a boolean for the same target', function () {
    $project = Project::factory()->create();
    Comment::factory()->create(['project_id' => $project->id]);

    expect($this->action->exists($project->path, $project->id))->toBeTrue()
        ->and($this->action->exists('/tmp/empty', null))->toBeFalse();
});

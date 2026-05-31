<?php

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use App\Models\TrashedFile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('deleting the last-opened project clears the cached slug', function () {
    $project = Project::factory()->create(['slug' => 'cached-project']);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('cached-project');

    $project->delete();

    expect($action->lastOpenedSlug())->toBeNull();
});

test('deleting a different project preserves the cached slug', function () {
    Project::factory()->create(['slug' => 'project-a']);
    $projectB = Project::factory()->create(['slug' => 'project-b']);

    $action = app(ResolveStartupRouteAction::class);
    $action->rememberLastOpened('project-a');

    $projectB->delete();

    expect($action->lastOpenedSlug())->toBe('project-a');
});

test('deleting a project purges its trashed-file content blobs', function () {
    Storage::fake();

    $project = Project::factory()->create();
    $trashed = TrashedFile::create([
        'project_id' => $project->id,
        'file_path' => 'a.txt',
        'file_status' => 'modified',
        'expires_at' => now()->addMinutes(30),
    ]);
    Storage::put($trashed->blobPath(), 'discarded content');

    expect(Storage::exists($trashed->blobPath()))->toBeTrue();

    // DB-level FK cascade would remove the row without firing model events;
    // ProjectObserver::deleting must purge the blob first.
    $project->delete();

    expect(TrashedFile::find($trashed->id))->toBeNull();
    expect(Storage::exists("trash/{$trashed->id}"))->toBeFalse();
});

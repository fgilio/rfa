<?php

use App\Actions\DeleteTrashedFileAction;
use App\Enums\DiscardOperation;
use App\Models\Project;
use App\Models\TrashedFile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::create([
        'slug' => 'test-delete-trash',
        'name' => 'Test',
        'path' => '/tmp/test-delete-trash',
        'git_common_dir' => '/tmp/test-delete-trash/.git',
    ]);

    $this->action = app(DeleteTrashedFileAction::class);

    Storage::fake();
});

test('deletes trash entry and storage file', function () {
    $trashed = TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'file.txt',
        'operation' => DiscardOperation::ModificationReverted,
        'expires_at' => now()->addMinutes(30),
    ]);
    Storage::put("trash/{$trashed->id}", 'content');

    $this->action->handle($trashed->id, $this->project->id);

    expect(TrashedFile::find($trashed->id))->toBeNull();
    expect(Storage::exists("trash/{$trashed->id}"))->toBeFalse();
});

test('no-ops for nonexistent id', function () {
    $this->action->handle(99999, $this->project->id);

    // No exception thrown
    expect(true)->toBeTrue();
});

test('rejects entry from another project', function () {
    $otherProject = Project::create([
        'slug' => 'other-del',
        'name' => 'Other',
        'path' => '/tmp/other-del',
        'git_common_dir' => '/tmp/other-del/.git',
    ]);

    $trashed = TrashedFile::create([
        'project_id' => $otherProject->id,
        'file_path' => 'file.txt',
        'operation' => DiscardOperation::ModificationReverted,
        'expires_at' => now()->addMinutes(30),
    ]);
    Storage::put("trash/{$trashed->id}", 'content');

    // Try to delete using wrong project
    $this->action->handle($trashed->id, $this->project->id);

    // Entry should still exist
    expect(TrashedFile::find($trashed->id))->not->toBeNull();
    expect(Storage::exists("trash/{$trashed->id}"))->toBeTrue();
});

test('handles entry without storage file', function () {
    $trashed = TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'deleted-file.txt',
        'operation' => DiscardOperation::DeletionReverted,
        'expires_at' => now()->addMinutes(30),
    ]);

    // No storage file created (like a deleted file discard)
    $this->action->handle($trashed->id, $this->project->id);

    expect(TrashedFile::find($trashed->id))->toBeNull();
});

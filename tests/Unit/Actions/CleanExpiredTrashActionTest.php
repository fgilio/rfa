<?php

use App\Actions\CleanExpiredTrashAction;
use App\Models\Project;
use App\Models\TrashedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::create([
        'slug' => 'test-clean',
        'name' => 'Test',
        'path' => '/tmp/test-clean',
        'git_common_dir' => '/tmp/test-clean/.git',
    ]);

    $this->action = app(CleanExpiredTrashAction::class);

    Storage::fake();
});

test('removes expired entries and their storage files', function () {
    $expired = TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'old.txt',
        'file_status' => 'modified',
        'expires_at' => now()->subMinutes(5),
    ]);
    Storage::put("trash/{$expired->id}", 'old content');

    $this->action->handle($this->project->id);

    expect(TrashedFile::find($expired->id))->toBeNull();
    expect(Storage::exists("trash/{$expired->id}"))->toBeFalse();
});

test('returns active entries only', function () {
    TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'active.txt',
        'file_status' => 'modified',
        'expires_at' => now()->addMinutes(10),
    ]);

    TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'expired.txt',
        'file_status' => 'added',
        'expires_at' => now()->subMinute(),
    ]);

    $result = $this->action->handle($this->project->id);

    expect($result)->toHaveCount(1);
    expect($result[0]['file_path'])->toBe('active.txt');
});

test('does not touch entries from other projects', function () {
    $otherProject = Project::create([
        'slug' => 'other',
        'name' => 'Other',
        'path' => '/tmp/other',
        'git_common_dir' => '/tmp/other/.git',
    ]);

    $otherExpired = TrashedFile::create([
        'project_id' => $otherProject->id,
        'file_path' => 'other.txt',
        'file_status' => 'modified',
        'expires_at' => now()->subMinutes(5),
    ]);

    $this->action->handle($this->project->id);

    // Other project's expired entry should still exist
    expect(TrashedFile::find($otherExpired->id))->not->toBeNull();
});

test('returns empty array when no active entries', function () {
    TrashedFile::create([
        'project_id' => $this->project->id,
        'file_path' => 'expired.txt',
        'file_status' => 'modified',
        'expires_at' => now()->subMinute(),
    ]);

    $result = $this->action->handle($this->project->id);

    expect($result)->toBe([]);
});

<?php

use App\Actions\DiscardFileChangesAction;
use App\Actions\RestoreDiscardedFileAction;
use App\Models\Project;
use App\Models\TrashedFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_restore_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "original\n");
    $this->commitTestRepo($this->tmpDir, 'init');

    $this->project = Project::create([
        'slug' => 'test-restore',
        'name' => 'Test',
        'path' => $this->tmpDir,
        'git_common_dir' => $this->tmpDir.'/.git',
    ]);

    $this->discardAction = app(DiscardFileChangesAction::class);
    $this->restoreAction = app(RestoreDiscardedFileAction::class);

    Storage::fake();
});

// -- modified files --

test('restores modified file', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $trashed = $this->discardAction->handle($this->tmpDir, 'file.txt', 'modified', $this->project->id);

    // File is now original
    expect(File::get($this->tmpDir.'/file.txt'))->toBe("original\n");

    $comments = $this->restoreAction->handle($trashed->id, $this->tmpDir, $this->project->id);

    // File should be back to changed
    expect(File::get($this->tmpDir.'/file.txt'))->toBe("changed\n");
    expect($comments)->toBe([]);

    // Trash record and storage cleaned up
    expect(TrashedFile::find($trashed->id))->toBeNull();
    expect(Storage::exists("trash/{$trashed->id}"))->toBeFalse();
});

// -- deleted files --

test('restores deleted file (re-deletes the restored file)', function () {
    File::delete($this->tmpDir.'/file.txt');

    $trashed = $this->discardAction->handle($this->tmpDir, 'file.txt', 'deleted', $this->project->id);

    // Discard restored the file from HEAD
    expect(File::exists($this->tmpDir.'/file.txt'))->toBeTrue();

    $this->restoreAction->handle($trashed->id, $this->tmpDir, $this->project->id);

    // Restore should delete it again
    expect(File::exists($this->tmpDir.'/file.txt'))->toBeFalse();
});

// -- added files --

test('restores untracked added file', function () {
    File::put($this->tmpDir.'/new.txt', "brand new\n");

    $trashed = $this->discardAction->handle(
        $this->tmpDir, 'new.txt', 'added', $this->project->id, isUntracked: true
    );

    expect(File::exists($this->tmpDir.'/new.txt'))->toBeFalse();

    $this->restoreAction->handle($trashed->id, $this->tmpDir, $this->project->id);

    expect(File::get($this->tmpDir.'/new.txt'))->toBe("brand new\n");
});

// -- renamed files --

test('restores renamed file', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git mv file.txt renamed.txt');

    $trashed = $this->discardAction->handle(
        $this->tmpDir, 'renamed.txt', 'renamed', $this->project->id, oldPath: 'file.txt'
    );

    // After discard: old path restored, new path gone
    expect(File::exists($this->tmpDir.'/file.txt'))->toBeTrue();
    expect(File::exists($this->tmpDir.'/renamed.txt'))->toBeFalse();

    $this->restoreAction->handle($trashed->id, $this->tmpDir, $this->project->id);

    // After restore: new path back, old path gone
    expect(File::exists($this->tmpDir.'/renamed.txt'))->toBeTrue();
    expect(File::exists($this->tmpDir.'/file.txt'))->toBeFalse();
});

// -- comments --

test('returns saved comments on restore', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");
    $comments = [['id' => 'c-1', 'body' => 'my comment', 'fileId' => 'file-abc']];

    $trashed = $this->discardAction->handle(
        $this->tmpDir, 'file.txt', 'modified', $this->project->id, comments: $comments
    );

    $restored = $this->restoreAction->handle($trashed->id, $this->tmpDir, $this->project->id);

    expect($restored)->toBe($comments);
});

// -- storage cleanup --

test('deletes storage file on restore', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $trashed = $this->discardAction->handle($this->tmpDir, 'file.txt', 'modified', $this->project->id);
    $storageKey = "trash/{$trashed->id}";

    expect(Storage::exists($storageKey))->toBeTrue();

    $this->restoreAction->handle($trashed->id, $this->tmpDir, $this->project->id);

    expect(Storage::exists($storageKey))->toBeFalse();
});

// -- not found --

test('throws on invalid trash id', function () {
    $this->restoreAction->handle(99999, $this->tmpDir, $this->project->id);
})->throws(ModelNotFoundException::class);

// -- project scoping --

test('rejects trash entry from another project', function () {
    $otherProject = Project::create([
        'slug' => 'other-project',
        'name' => 'Other',
        'path' => $this->tmpDir.'-other',
        'git_common_dir' => $this->tmpDir.'-other/.git',
    ]);

    File::put($this->tmpDir.'/file.txt', "changed\n");
    $trashed = $this->discardAction->handle($this->tmpDir, 'file.txt', 'modified', $this->project->id);

    // Try to restore using a different project's ID
    $this->restoreAction->handle($trashed->id, $this->tmpDir, $otherProject->id);
})->throws(ModelNotFoundException::class);

// -- expiry enforcement --

test('rejects expired trash entry', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");
    $trashed = $this->discardAction->handle($this->tmpDir, 'file.txt', 'modified', $this->project->id);

    // Manually expire the entry
    $trashed->update(['expires_at' => now()->subMinute()]);

    $this->restoreAction->handle($trashed->id, $this->tmpDir, $this->project->id);
})->throws(ModelNotFoundException::class);

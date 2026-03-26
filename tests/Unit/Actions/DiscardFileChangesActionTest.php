<?php

use App\Actions\DiscardFileChangesAction;
use App\Models\Project;
use App\Models\TrashedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_discard_test_');
    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/file.txt', "original\n");
    $this->commitTestRepo($this->tmpDir, 'init');

    $this->project = Project::create([
        'slug' => 'test-discard',
        'name' => 'Test',
        'path' => $this->tmpDir,
        'git_common_dir' => $this->tmpDir.'/.git',
    ]);

    $this->action = app(DiscardFileChangesAction::class);

    Storage::fake();
});

// -- modified files --

test('discards modified file and creates trash record', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $trashed = $this->action->handle($this->tmpDir, 'file.txt', 'modified', $this->project->id);

    expect($trashed)->toBeInstanceOf(TrashedFile::class)
        ->and($trashed->file_path)->toBe('file.txt')
        ->and($trashed->file_status)->toBe('modified')
        ->and($trashed->project_id)->toBe($this->project->id)
        ->and($trashed->expires_at)->toBeInstanceOf(\Carbon\Carbon::class);

    // File should be restored to original
    expect(File::get($this->tmpDir.'/file.txt'))->toBe("original\n");

    // Content should be saved in storage
    expect(Storage::exists("trash/{$trashed->id}"))->toBeTrue();
    expect(Storage::get("trash/{$trashed->id}"))->toBe("changed\n");
});

// -- deleted files --

test('discards deleted file', function () {
    File::delete($this->tmpDir.'/file.txt');

    $trashed = $this->action->handle($this->tmpDir, 'file.txt', 'deleted', $this->project->id);

    expect($trashed->file_status)->toBe('deleted');

    // File should be restored from HEAD
    expect(File::exists($this->tmpDir.'/file.txt'))->toBeTrue();
    expect(File::get($this->tmpDir.'/file.txt'))->toBe("original\n");

    // No content saved (file didn't exist in working tree)
    expect(Storage::exists("trash/{$trashed->id}"))->toBeFalse();
});

// -- added files (untracked) --

test('discards untracked added file', function () {
    File::put($this->tmpDir.'/new.txt', "brand new\n");

    $trashed = $this->action->handle(
        $this->tmpDir, 'new.txt', 'added', $this->project->id, isUntracked: true
    );

    expect($trashed->file_status)->toBe('added')
        ->and($trashed->is_untracked)->toBeTrue();

    // File should be removed
    expect(File::exists($this->tmpDir.'/new.txt'))->toBeFalse();

    // Content should be in trash
    expect(Storage::get("trash/{$trashed->id}"))->toBe("brand new\n");
});

// -- added files (tracked/staged) --

test('discards tracked added file', function () {
    File::put($this->tmpDir.'/staged.txt', "staged content\n");
    $this->runTestRepoCommand($this->tmpDir, 'git add staged.txt');

    $trashed = $this->action->handle(
        $this->tmpDir, 'staged.txt', 'added', $this->project->id, isUntracked: false
    );

    expect($trashed->file_status)->toBe('added');
    expect(File::exists($this->tmpDir.'/staged.txt'))->toBeFalse();
    expect(Storage::get("trash/{$trashed->id}"))->toBe("staged content\n");
});

// -- renamed files --

test('discards renamed file', function () {
    $this->runTestRepoCommand($this->tmpDir, 'git mv file.txt renamed.txt');

    $trashed = $this->action->handle(
        $this->tmpDir, 'renamed.txt', 'renamed', $this->project->id, oldPath: 'file.txt'
    );

    expect($trashed->file_status)->toBe('renamed')
        ->and($trashed->old_path)->toBe('file.txt');

    // Old path restored, new path gone
    expect(File::exists($this->tmpDir.'/file.txt'))->toBeTrue();
    expect(File::exists($this->tmpDir.'/renamed.txt'))->toBeFalse();
});

// -- binary files --

test('discards binary file', function () {
    $binaryContent = random_bytes(64);
    File::put($this->tmpDir.'/file.txt', $binaryContent);

    $trashed = $this->action->handle($this->tmpDir, 'file.txt', 'binary', $this->project->id);

    expect($trashed->file_status)->toBe('binary');
    expect(File::get($this->tmpDir.'/file.txt'))->toBe("original\n");
    expect(Storage::get("trash/{$trashed->id}"))->toBe($binaryContent);
});

// -- comments --

test('saves comments in trash record', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");
    $comments = [['id' => 'c-1', 'body' => 'test comment']];

    $trashed = $this->action->handle(
        $this->tmpDir, 'file.txt', 'modified', $this->project->id, comments: $comments
    );

    expect($trashed->comments)->toBe($comments);
});

test('stores null comments when empty', function () {
    File::put($this->tmpDir.'/file.txt', "changed\n");

    $trashed = $this->action->handle(
        $this->tmpDir, 'file.txt', 'modified', $this->project->id, comments: []
    );

    expect($trashed->comments)->toBeNull();
});

// -- path validation --

test('rejects path traversal', function () {
    $this->action->handle($this->tmpDir, '../etc/passwd', 'modified', $this->project->id);
})->throws(\InvalidArgumentException::class);

test('rejects absolute path', function () {
    $this->action->handle($this->tmpDir, '/etc/passwd', 'modified', $this->project->id);
})->throws(\InvalidArgumentException::class);

// -- cleanup on failure --

test('cleans up trash record on git failure', function () {
    // Try to discard a file that doesn't exist in git (will fail git restore)
    File::put($this->tmpDir.'/nonexistent.txt', "test\n");

    $countBefore = TrashedFile::count();

    try {
        $this->action->handle($this->tmpDir, 'nonexistent.txt', 'modified', $this->project->id);
    } catch (\Throwable) {
        // expected
    }

    expect(TrashedFile::count())->toBe($countBefore);
});

// -- symlinks --

test('discards symlink file', function () {
    $target = $this->tmpDir.'/file.txt';
    $link = $this->tmpDir.'/link.txt';
    symlink($target, $link);

    File::put($this->tmpDir.'/file.txt', "changed\n");
    $this->commitTestRepo($this->tmpDir, 'add link');
    File::put($this->tmpDir.'/file.txt', "modified again\n");

    $trashed = $this->action->handle(
        $this->tmpDir, 'link.txt', 'added', $this->project->id, isUntracked: true, isSymlink: true
    );

    expect($trashed->is_symlink)->toBeTrue();
    // Symlink target should be stored
    expect(Storage::get("trash/{$trashed->id}"))->toBe($target);
});

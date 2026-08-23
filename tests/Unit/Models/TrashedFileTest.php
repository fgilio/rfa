<?php

use App\Enums\DiscardOperation;
use App\Models\Project;
use App\Models\TrashedFile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('a trashed file needs an operation', function () {
    expect(fn () => TrashedFile::create([
        'project_id' => Project::factory()->create()->id,
        'file_path' => 'src/App.php',
        'expires_at' => now()->addMinutes(30),
    ]))->toThrow(InvalidArgumentException::class, 'The operation field is required to trash a file.');
});

test('only a rename may store an old path', function () {
    expect(fn () => TrashedFile::factory()->create([
        'operation' => DiscardOperation::ModificationReverted,
        'old_path' => 'src/Old.php',
    ]))->toThrow(InvalidArgumentException::class, 'The old_path field must be empty for a modification-reverted discard.');
});

test('a rename must store an old path', function () {
    expect(fn () => TrashedFile::factory()->create([
        'operation' => DiscardOperation::RenameReverted,
        'old_path' => null,
    ]))->toThrow(InvalidArgumentException::class, 'The old_path field is required for a rename-reverted discard.');
});

test('a rename with its old path saves', function () {
    $trashed = TrashedFile::factory()->renamed('src/Old.php')->create();

    expect($trashed->operation)->toBe(DiscardOperation::RenameReverted)
        ->and($trashed->old_path)->toBe('src/Old.php');
});

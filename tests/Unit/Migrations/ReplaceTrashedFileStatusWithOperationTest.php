<?php

use App\Enums\DiscardOperation;
use App\Models\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('every legacy field combination backfills to one operation', function () {
    $project = Project::factory()->create();

    recreateLegacyTrashedFilesTable();

    $legacyRows = [
        ['file_path' => 'a.txt', 'file_status' => 'added', 'is_untracked' => true, 'old_path' => null],
        ['file_path' => 'b.txt', 'file_status' => 'added', 'is_untracked' => false, 'old_path' => null],
        ['file_path' => 'c.txt', 'file_status' => 'renamed', 'is_untracked' => false, 'old_path' => 'was-c.txt'],
        ['file_path' => 'd.txt', 'file_status' => 'deleted', 'is_untracked' => false, 'old_path' => null],
        ['file_path' => 'e.txt', 'file_status' => 'modified', 'is_untracked' => false, 'old_path' => null],
        ['file_path' => 'f.txt', 'file_status' => 'binary', 'is_untracked' => false, 'old_path' => null],
    ];

    foreach ($legacyRows as $row) {
        DB::table('trashed_files')->insert([
            ...$row,
            'project_id' => $project->id,
            'is_symlink' => false,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    trashedFileOperationMigration()->up();

    expect(DB::table('trashed_files')->pluck('operation', 'file_path')->all())->toBe([
        'a.txt' => DiscardOperation::UntrackedFileDeleted->value,
        'b.txt' => DiscardOperation::AddedFileRemoved->value,
        'c.txt' => DiscardOperation::RenameReverted->value,
        'd.txt' => DiscardOperation::DeletionReverted->value,
        'e.txt' => DiscardOperation::ModificationReverted->value,
        'f.txt' => DiscardOperation::ModificationReverted->value,
    ]);

    expect(Schema::hasColumn('trashed_files', 'file_status'))->toBeFalse()
        ->and(Schema::hasColumn('trashed_files', 'is_untracked'))->toBeFalse();
});

function trashedFileOperationMigration(): object
{
    return require database_path('migrations/2026_08_23_120000_replace_trashed_file_status_with_operation.php');
}

/** The pre-migration schema, so the backfill runs against rows it would actually find. */
function recreateLegacyTrashedFilesTable(): void
{
    Schema::dropIfExists('trashed_files');

    Schema::create('trashed_files', function (Blueprint $table) {
        $table->id();
        $table->foreignId('project_id')->constrained()->cascadeOnDelete();
        $table->string('file_path');
        $table->string('file_status');
        $table->string('old_path')->nullable();
        $table->boolean('is_untracked')->default(false);
        $table->boolean('is_symlink')->default(false);
        $table->json('comments')->nullable();
        $table->timestamp('expires_at');
        $table->timestamps();
    });
}

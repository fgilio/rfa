<?php

use App\Enums\DiscardOperation;
use App\Models\Project;
use App\Models\TrashedFile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/**
 * @return array<int, array<string, mixed>>
 */
function legacyTrashedFileRows(): array
{
    return [
        ['file_path' => 'a.txt', 'file_status' => 'added', 'is_untracked' => true, 'old_path' => null],
        ['file_path' => 'b.txt', 'file_status' => 'added', 'is_untracked' => false, 'old_path' => null],
        ['file_path' => 'c.txt', 'file_status' => 'renamed', 'is_untracked' => false, 'old_path' => 'was-c.txt'],
        ['file_path' => 'd.txt', 'file_status' => 'deleted', 'is_untracked' => false, 'old_path' => null],
        ['file_path' => 'e.txt', 'file_status' => 'modified', 'is_untracked' => false, 'old_path' => null],
        ['file_path' => 'f.txt', 'file_status' => 'binary', 'is_untracked' => false, 'old_path' => null],
        // The legacy writer stored whatever old_path it was handed, so a
        // non-rename row can carry one. The backfill has to clear it.
        ['file_path' => 'g.txt', 'file_status' => 'modified', 'is_untracked' => false, 'old_path' => 'stale-g.txt'],
    ];
}

function seedLegacyTrashedFiles(): void
{
    $project = Project::factory()->create();

    recreateLegacyTrashedFilesTable();

    collect(legacyTrashedFileRows())->each(fn (array $row) => DB::table('trashed_files')->insert([
        ...$row,
        'project_id' => $project->id,
        'is_symlink' => false,
        'expires_at' => now()->addMinutes(30),
        'created_at' => now(),
        'updated_at' => now(),
    ]));
}

test('every legacy field combination backfills to one operation', function () {
    seedLegacyTrashedFiles();

    trashedFileOperationMigration()->up();

    expect(DB::table('trashed_files')->pluck('operation', 'file_path')->all())->toBe([
        'a.txt' => DiscardOperation::UntrackedFileDeleted->value,
        'b.txt' => DiscardOperation::AddedFileRemoved->value,
        'c.txt' => DiscardOperation::RenameReverted->value,
        'd.txt' => DiscardOperation::DeletionReverted->value,
        'e.txt' => DiscardOperation::ModificationReverted->value,
        'f.txt' => DiscardOperation::ModificationReverted->value,
        'g.txt' => DiscardOperation::ModificationReverted->value,
    ]);

    expect(Schema::hasColumn('trashed_files', 'file_status'))->toBeFalse()
        ->and(Schema::hasColumn('trashed_files', 'is_untracked'))->toBeFalse();
});

test('the backfill clears an old_path the migrated operation cannot carry', function () {
    seedLegacyTrashedFiles();

    trashedFileOperationMigration()->up();

    expect(DB::table('trashed_files')->pluck('old_path', 'file_path')->all())
        ->toBe([
            'a.txt' => null,
            'b.txt' => null,
            'c.txt' => 'was-c.txt',
            'd.txt' => null,
            'e.txt' => null,
            'f.txt' => null,
            'g.txt' => null,
        ]);
});

test('every migrated row satisfies the model contract it will be saved under', function () {
    seedLegacyTrashedFiles();

    trashedFileOperationMigration()->up();

    // A row that migrates into a combination TrashedFile rejects would be
    // permanently unsaveable, so re-saving each one is the real assertion.
    TrashedFile::query()->get()->each(fn (TrashedFile $trashed) => $trashed->touch());
})->throwsNoExceptions();

test('down restores the legacy columns and values', function () {
    seedLegacyTrashedFiles();

    $migration = trashedFileOperationMigration();
    $migration->up();
    $migration->down();

    expect(Schema::hasColumn('trashed_files', 'operation'))->toBeFalse()
        ->and(Schema::hasColumn('trashed_files', 'file_status'))->toBeTrue()
        ->and(Schema::hasColumn('trashed_files', 'is_untracked'))->toBeTrue();

    $restored = DB::table('trashed_files')
        ->get()
        ->mapWithKeys(fn (object $row): array => [
            $row->file_path => [(string) $row->file_status, (bool) $row->is_untracked],
        ])
        ->all();

    expect($restored)->toBe([
        'a.txt' => ['added', true],
        'b.txt' => ['added', false],
        'c.txt' => ['renamed', false],
        'd.txt' => ['deleted', false],
        'e.txt' => ['modified', false],
        // 'binary' is not a discard operation of its own, so it round-trips as
        // the 'modified' it always mapped to.
        'f.txt' => ['modified', false],
        'g.txt' => ['modified', false],
    ]);
});

function trashedFileOperationMigration(): object
{
    return require database_path('migrations/2026_08_23_120000_replace_trashed_file_status_with_operation.php');
}

/**
 * Rebuild the pre-migration schema by replaying the create migration, so the
 * backfill runs against the real legacy shape rather than a copy of it that can
 * silently drift.
 */
function recreateLegacyTrashedFilesTable(): void
{
    $create = require database_path('migrations/2026_03_15_000000_create_trashed_files_table.php');

    $create->down();
    $create->up();
}

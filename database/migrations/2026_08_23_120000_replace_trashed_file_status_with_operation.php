<?php

use App\Enums\DiscardOperation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trashed_files', function (Blueprint $table) {
            $table->string('operation')->nullable()->after('file_path');
        });

        // `old_path` is normalized alongside the operation, not just carried
        // over: the legacy writer stored whatever it was handed, so a non-rename
        // row can hold one. Left in place it would satisfy the schema but
        // violate the operation/old_path pairing TrashedFile now enforces,
        // making the row unsaveable the next time anything touched it.
        DB::table('trashed_files')->orderBy('id')->lazyById()->each(function (object $row): void {
            $operation = DiscardOperation::forChangedFile(
                (string) $row->file_status,
                (bool) $row->is_untracked,
            );

            DB::table('trashed_files')->where('id', $row->id)->update([
                'operation' => $operation->value,
                'old_path' => $operation->usesOldPath() ? $row->old_path : null,
            ]);
        });

        Schema::table('trashed_files', function (Blueprint $table) {
            $table->string('operation')->nullable(false)->change();
            $table->dropColumn(['file_status', 'is_untracked']);
        });
    }

    public function down(): void
    {
        Schema::table('trashed_files', function (Blueprint $table) {
            $table->string('file_status')->default('modified');
            $table->boolean('is_untracked')->default(false);
        });

        DB::table('trashed_files')->orderBy('id')->lazyById()->each(function (object $row): void {
            $operation = DiscardOperation::tryFrom((string) $row->operation) ?? DiscardOperation::ModificationReverted;

            DB::table('trashed_files')->where('id', $row->id)->update([
                'file_status' => match ($operation) {
                    DiscardOperation::UntrackedFileDeleted, DiscardOperation::AddedFileRemoved => 'added',
                    DiscardOperation::RenameReverted => 'renamed',
                    DiscardOperation::DeletionReverted => 'deleted',
                    DiscardOperation::ModificationReverted => 'modified',
                },
                'is_untracked' => $operation === DiscardOperation::UntrackedFileDeleted,
            ]);
        });

        Schema::table('trashed_files', function (Blueprint $table) {
            $table->dropColumn('operation');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('review_sessions', 'viewed_files') && ! Schema::hasColumn('review_sessions', 'reviewed_files')) {
            Schema::table('review_sessions', function (Blueprint $table) {
                $table->renameColumn('viewed_files', 'reviewed_files');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('review_sessions', 'reviewed_files') && ! Schema::hasColumn('review_sessions', 'viewed_files')) {
            Schema::table('review_sessions', function (Blueprint $table) {
                $table->renameColumn('reviewed_files', 'viewed_files');
            });
        }
    }
};

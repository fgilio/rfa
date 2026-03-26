<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->string('context_fingerprint')->default('working')->after('project_id');
        });

        // Drop old unique on project_id, add composite unique
        Schema::table('review_sessions', function (Blueprint $table) {
            $table->dropUnique(['project_id']);
            $table->unique(['project_id', 'context_fingerprint']);
        });
    }

    public function down(): void
    {
        // Remove duplicate rows keeping the 'working' context per project
        DB::table('review_sessions')
            ->where('context_fingerprint', '!=', 'working')
            ->delete();

        Schema::table('review_sessions', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'context_fingerprint']);
            $table->unique('project_id');
            $table->dropColumn('context_fingerprint');
        });
    }
};
